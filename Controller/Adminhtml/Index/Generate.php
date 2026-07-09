<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Query\Completions;
use Nistruct\ContentAI\Model\ReportFactory;
use Psr\Log\LoggerInterface;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::generate';

    private $resultJson;
    private $productRepository;
    private $queryCompletion;
    private $helper;
    private $logger;
    private $reportFactory;
    private $storeManager;
    private $directoryList;
    private $productAction;
    private $categoryRepository;
    private $resourceConnection;
    private $eavConfig;
    private $cache;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJson,
        ProductRepositoryInterface $productRepository,
        Completions $queryCompletion,
        HelperData $helper,
        LoggerInterface $logger,
        ReportFactory $reportFactory,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        ProductAction $productAction,
        CategoryRepositoryInterface $categoryRepository,
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        CacheInterface $cache
    ) {
        parent::__construct($context);
        $this->resultJson = $resultJson;
        $this->productRepository = $productRepository;
        $this->queryCompletion = $queryCompletion;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->reportFactory = $reportFactory;
        $this->storeManager = $storeManager;
        $this->directoryList = $directoryList;
        $this->productAction = $productAction;
        $this->categoryRepository = $categoryRepository;
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->cache = $cache;
    }

    public function execute()
    {
        $response = ['error' => true, 'data' => (string) __('ContentAI is disabled.')];
        if ($this->helper->isEnabled()) {
            try {
                if ((string) $this->getRequest()->getParam('generate_fields') === '1') {
                    $response = $this->generateSelectedFields();
                } elseif ((string) $this->getRequest()->getParam('apply_fields') === '1') {
                    $response = $this->applySelectedFields();
                } else {
                    $response = ['error' => true, 'data' => (string) __('Unsupported ContentAI generation request.')];
                }
            } catch (\Exception $e) {
                $response = ['error' => true, 'data' => $e->getMessage()];
            }
        }

        return $this->resultJson->create()->setData($response);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    private function generateSelectedFields(): array
    {
        if ($this->getEntityType() === 'category') {
            return $this->generateSelectedCategoryFields();
        }

        $selectedFields = $this->decodeJsonParam('selected_fields');
        $productData = $this->decodeJsonParam('product_data');
        $sku = trim((string) $this->getRequest()->getParam('sku', ''));
        $storeId = $this->getRequestStoreId();
        $language = $this->helper->getLanguageByStoreId($storeId);
        $productData = $this->enrichProductData($productData, $sku);

        if (empty($selectedFields)) {
            return ['error' => true, 'data' => (string) __('No fields selected.')];
        }

        $prompt = $this->buildFieldsPrompt($selectedFields, $productData, $language, 'product');
        $imagePayload = $this->getContextImageUrl($productData);
        $this->queryCompletion->resetUsageMetadata();
        $fields = $this->decodeFieldsResponse($this->queryCompletion->generateContent($prompt, $imagePayload));
        $fields = $this->retryWrongLanguageResponse($fields, $prompt, $language, $imagePayload);
        $usageMetadata = $this->queryCompletion->getUsageMetadata();

        $allowedCodes = [];
        foreach ($selectedFields as $field) {
            if (!empty($field['code'])) {
                $allowedCodes[(string) $field['code']] = true;
            }
        }

        $filtered = [];
        foreach ($fields as $code => $value) {
            $code = $this->normalizeReturnedFieldCode((string) $code);
            if (!isset($allowedCodes[$code]) || !is_scalar($value)) {
                continue;
            }
            $filtered[$code] = $this->helper->sanitizeHtml((string) $value);
        }

        if (empty($filtered)) {
            return ['error' => true, 'data' => (string) __('AI response did not contain selected fields.')];
        }

        try {
            $report = $this->reportFactory->create();
            $report->setData('entity_type', 'product');
            $report->setProductId($this->getProductIdBySku($sku));
            $report->setProductSku($sku !== '' ? $sku : null);
            $report->setStoreId($storeId);
            $report->setData('generated_content', json_encode($filtered, JSON_UNESCAPED_UNICODE));
            $report->setData('usage_metadata', json_encode($usageMetadata));
            $report->setCreatedAt(date('Y-m-d H:i:s'));
            $report->setGeneratorType('single');
            $report->save();
        } catch (\Exception $e) {
            $this->logger->error('ContentAI report save error: ' . $e->getMessage());
        }

        return ['error' => false, 'data' => ['fields' => $filtered]];
    }

    private function applySelectedFields(): array
    {
        if ($this->getEntityType() === 'category') {
            return $this->applySelectedCategoryFields();
        }

        $sku = trim((string) $this->getRequest()->getParam('sku', ''));
        $storeId = $this->getRequestStoreId();
        $fields = $this->decodeJsonParam('fields');

        if ($sku === '' || empty($fields)) {
            return ['error' => true, 'data' => (string) __('No fields selected for apply.')];
        }

        $product = $this->productRepository->get($sku, false, $storeId, true);
        $saved = [];
        foreach ($fields as $code => $value) {
            $targetCode = $this->getTargetProductFieldCode($this->normalizeReturnedFieldCode((string) $code));
            if (!$this->isAllowedProductField($targetCode) || !is_scalar($value)) {
                continue;
            }
            if (!$product->getResource()->getAttribute($targetCode)) {
                continue;
            }
            $saved[$targetCode] = $this->helper->sanitizeHtml((string) $value);
        }

        if (empty($saved)) {
            return ['error' => true, 'data' => (string) __('No valid product fields selected for apply.')];
        }

        $this->productAction->updateAttributes([(int) $product->getId()], $saved, $storeId);
        $this->logger->info(sprintf('ContentAI applied fields for SKU %s store %d: %s', $sku, $storeId, implode(',', array_keys($saved))));

        return ['error' => false, 'data' => ['fields' => $saved]];
    }

    private function generateSelectedCategoryFields(): array
    {
        $selectedFields = $this->decodeJsonParam('selected_fields');
        $categoryData = $this->decodeJsonParam('category_data');
        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId = $this->getRequestStoreId();
        $language = $this->helper->getLanguageByStoreId($storeId);

        if ($categoryId <= 0) {
            return ['error' => true, 'data' => (string) __('Category ID is missing.')];
        }
        if (empty($selectedFields)) {
            return ['error' => true, 'data' => (string) __('No fields selected.')];
        }

        $category = $this->categoryRepository->get($categoryId, $storeId);
        $categoryData = $this->enrichCategoryData($categoryData, $category);
        $prompt = $this->buildFieldsPrompt($selectedFields, $categoryData, $language, 'category');

        $this->queryCompletion->resetUsageMetadata();
        $fields = $this->decodeFieldsResponse($this->queryCompletion->generateContent($prompt));
        $fields = $this->retryWrongLanguageResponse($fields, $prompt, $language, '');
        $usageMetadata = $this->queryCompletion->getUsageMetadata();

        $allowedCodes = [];
        foreach ($selectedFields as $field) {
            if (!empty($field['code'])) {
                $allowedCodes[(string) $field['code']] = true;
            }
        }

        $filtered = [];
        foreach ($fields as $code => $value) {
            $code = $this->normalizeReturnedFieldCode((string) $code, 'category');
            if (!isset($allowedCodes[$code]) || !is_scalar($value)) {
                continue;
            }
            $filtered[$code] = $this->helper->sanitizeHtml((string) $value);
        }

        if (empty($filtered)) {
            return ['error' => true, 'data' => (string) __('AI response did not contain selected fields.')];
        }

        try {
            $report = $this->reportFactory->create();
            $report->setData('entity_type', 'category');
            $report->setData('category_id', $categoryId);
            $report->setData('category_name', (string) $category->getName());
            $report->setStoreId($storeId);
            $report->setData('generated_content', json_encode($filtered, JSON_UNESCAPED_UNICODE));
            $report->setData('usage_metadata', json_encode($usageMetadata));
            $report->setCreatedAt(date('Y-m-d H:i:s'));
            $report->setGeneratorType('single');
            $report->save();
        } catch (\Exception $e) {
            $this->logger->error('ContentAI category report save error: ' . $e->getMessage());
        }

        return ['error' => false, 'data' => ['fields' => $filtered]];
    }

    private function applySelectedCategoryFields(): array
    {
        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId = $this->getRequestStoreId();
        $fields = $this->decodeJsonParam('fields');

        if ($categoryId <= 0 || empty($fields)) {
            return ['error' => true, 'data' => (string) __('No category fields selected for apply.')];
        }

        $saved = [];
        foreach ($fields as $code => $value) {
            $code = $this->normalizeReturnedFieldCode((string) $code, 'category');
            if (!$this->isAllowedCategoryField($code) || !is_scalar($value)) {
                continue;
            }

            $saved[$code] = $this->helper->sanitizeHtml((string) $value);
        }

        if (empty($saved)) {
            return ['error' => true, 'data' => (string) __('No valid category fields selected for apply.')];
        }

        $this->saveCategoryAttributeValues($categoryId, $storeId, $saved);
        $this->cache->clean(['catalog_category_' . $categoryId]);
        $this->logger->info(sprintf('ContentAI applied category fields for category %d store %d: %s', $categoryId, $storeId, implode(',', array_keys($saved))));

        return ['error' => false, 'data' => ['fields' => $saved]];
    }

    private function saveCategoryAttributeValues(int $categoryId, int $storeId, array $values): void
    {
        $connection = $this->resourceConnection->getConnection();

        foreach ($values as $code => $value) {
            $attribute = $this->eavConfig->getAttribute('catalog_category', $code);
            if (!$attribute || !(int) $attribute->getAttributeId()) {
                continue;
            }

            $backendType = (string) $attribute->getBackendType();
            if ($backendType === '' || $backendType === 'static') {
                continue;
            }

            $table = $this->resourceConnection->getTableName('catalog_category_entity_' . $backendType);
            $connection->insertOnDuplicate(
                $table,
                [
                    'attribute_id' => (int) $attribute->getAttributeId(),
                    'store_id' => $storeId,
                    'entity_id' => $categoryId,
                    'value' => (string) $value,
                ],
                ['value']
            );
        }
    }

    private function buildFieldsPrompt(array $selectedFields, array $productData, string $language, string $entityLabel = 'product'): string
    {
        $entityLabel = strtolower($entityLabel) === 'category' ? 'category' : 'product';
        $lines = [
            'Generate improved Magento ' . $entityLabel . ' field values for the selected output fields.',
            'The target language is determined only by the Magento store view/scope, not by the language of existing product data.',
            'Target language: ' . $language . '.',
            'All generated field values must be written strictly in the target language.',
            'Use current ' . $entityLabel . ' data only as factual source material. Do not use it as the language, tone, or final wording authority.',
            'If current ' . $entityLabel . ' data is in a different language, extract the facts and write fresh improved content in the target language. Do not produce a literal translation and do not return the same text unchanged.',
            'Keep only brand names, SKU values, model names, product codes, and established product names unchanged.',
            'When the target language is English, return English wording only; do not return Serbian, Croatian, or Bosnian words unless they are part of a brand/product/model name.',
            'For Serbian use Serbian Latin wording, not Croatian or Bosnian wording.',
            'For Croatian/Bosnian use Croatian/Bosnian Latin wording.',
            'Return only a valid JSON object. Do not include markdown, comments, explanations, or code fences.',
            'The JSON object keys must be exactly the selected output field codes.',
            'Do not return fields that were not selected as output fields.',
            'Do not invent specifications, certifications, dimensions, prices, stock, or claims that are not present in the product data.',
            'Use clean HTML only for rich text description-like fields. Use plain text for titles, names, meta fields, URL keys, and short scalar fields.',
            'For meta_title keep it concise. For meta_description keep it suitable for search snippets.',
            'For meta_keyword or meta_keywords return comma-separated keywords in the target language, except brand names, SKU values, and established product names.',
            'For image label fields return concise plain text describing the product image for accessibility and image context.',
            'If an image is provided as part of the message, use it as visual context for product appearance, shape, style, color, and visible product features.',
            '',
            'Selected fields:'
        ];
        foreach ($selectedFields as $field) {
            $code = (string) ($field['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $lines[] = sprintf('- %s (%s), current value: %s', (string) ($field['label'] ?? $code), $code, $this->normalizePromptValue((string) ($field['value'] ?? '')));
        }
        $lines[] = '';
        $lines[] = 'Current ' . $entityLabel . ' data:';
        foreach ($productData as $code => $field) {
            if (!is_array($field) || strpos((string) $code, '_') === 0) {
                continue;
            }
            $label = (string) ($field['label'] ?? $code);
            $value = (string) ($field['value'] ?? '');
            if (!$this->shouldIncludePromptField((string) $code, $label, $value)) {
                continue;
            }
            $lines[] = sprintf('- %s (%s): %s', $label, (string) $code, $this->normalizePromptValue($value));
        }
        $lines[] = '';
        $lines[] = 'Return format example: {"name":"New product name","meta_title":"New meta title"}';
        return implode("\n", $lines);
    }

    private function enrichProductData(array $productData, string $sku): array
    {
        if ($sku === '') {
            return $productData;
        }
        try {
            $product = $this->productRepository->get($sku, false, $this->getRequestStoreId(), true);
            foreach ($product->getData() as $code => $value) {
                if (!is_scalar($value) || isset($productData[$code]) || !$this->hasUsefulPromptValue((string) $value)) {
                    continue;
                }
                $attribute = $product->getResource()->getAttribute((string) $code);
                $label = $attribute && $attribute->getFrontendLabel() ? (string) $attribute->getFrontendLabel() : (string) $code;
                $productData[$code] = ['label' => $label, 'value' => $this->getReadableAttributeValue($product, (string) $code, (string) $value)];
            }
            $imagePayload = $this->getProductImagePayload($product);
            if ($imagePayload !== '') {
                $productData['_image_payload'] = ['label' => 'Product Image', 'value' => $imagePayload];
            }
        } catch (\Exception $e) {
            $this->logger->warning('ContentAI product data enrich failed: ' . $e->getMessage());
        }
        return $productData;
    }

    private function enrichCategoryData(array $categoryData, $category): array
    {
        foreach ($category->getData() as $code => $value) {
            if (!is_scalar($value) || isset($categoryData[$code]) || !$this->hasUsefulPromptValue((string) $value)) {
                continue;
            }

            $attribute = $category->getResource()->getAttribute((string) $code);
            $label = $attribute && $attribute->getFrontendLabel() ? (string) $attribute->getFrontendLabel() : (string) $code;
            $categoryData[$code] = [
                'label' => $label,
                'value' => $this->getReadableAttributeValue($category, (string) $code, (string) $value),
            ];
        }

        return $categoryData;
    }

    private function getReadableAttributeValue($product, string $code, string $value): string
    {
        try {
            $text = $product->getAttributeText($code);
            if (is_array($text)) {
                $text = implode(', ', $text);
            }
            if (is_scalar($text) && trim((string) $text) !== '') {
                return (string) $text;
            }
        } catch (\Exception $e) {
            return $value;
        }
        return $value;
    }

    private function retryWrongLanguageResponse(array $fields, string $prompt, string $language, string $imagePayload): array
    {
        if (stripos($language, 'english') === false || !$this->looksLikeSerbianCroatianBosnian($fields)) {
            return $fields;
        }
        $this->logger->warning('ContentAI detected non-English generated response for English target language. Retrying once.');
        $retryPrompt = $prompt . "\n\nThe previous generated JSON used the wrong language. Regenerate the same selected fields in English only. Do not copy Serbian, Croatian, or Bosnian wording from current product data.";
        try {
            return $this->decodeFieldsResponse($this->queryCompletion->generateContent($retryPrompt, $imagePayload));
        } catch (\Exception $e) {
            $this->logger->error('ContentAI language retry failed: ' . $e->getMessage());
            return $fields;
        }
    }

    private function looksLikeSerbianCroatianBosnian(array $fields): bool
    {
        $text = mb_strtolower(implode(' ', array_map('strval', $fields)));
        if (preg_match('/[čćšđž]/iu', $text)) {
            return true;
        }
        return (bool) preg_match('/\b(sustav|proizvod|proizvoda|kutij|niskoklizn|folij|podlog|pričvrš|ucvr|učvr|ovjes|pakiranj|iskustvo otvaranja)\b/iu', $text);
    }

    private function decodeFieldsResponse(string $rawData): array
    {
        $rawData = trim($rawData);
        $rawData = preg_replace('/^```(?:json)?\s*/i', '', $rawData);
        $rawData = preg_replace('/\s*```$/', '', $rawData);
        $decoded = json_decode($rawData, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($rawData, '{');
        $end = strrpos($rawData, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($rawData, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeReturnedFieldCode(string $code, string $entityType = 'product'): string
    {
        if ($entityType === 'category') {
            return ['meta_keyword' => 'meta_keywords', 'keywords' => 'meta_keywords'][$code] ?? $code;
        }

        $aliases = ['meta_keywords' => 'meta_keyword', 'keywords' => 'meta_keyword', 'feature' => 'features', 'technical_features' => 'features', 'product_subtitle' => 'subtitle', 'tech_specs_features' => 'features'];
        return $aliases[$code] ?? $code;
    }

    private function getTargetProductFieldCode(string $code): string
    {
        return ['subtitle' => 'product_subtitle', 'features' => 'tech_specs_features'][$code] ?? $code;
    }

    private function isAllowedProductField(string $code): bool
    {
        return in_array($code, ['product_subtitle', 'tech_specs_features', 'short_description', 'description', 'meta_title', 'meta_keyword', 'meta_description', 'image_label', 'small_image_label', 'thumbnail_label'], true);
    }

    private function isAllowedCategoryField(string $code): bool
    {
        return in_array($code, ['description', 'meta_title', 'meta_keywords', 'meta_description'], true);
    }

    private function getEntityType(): string
    {
        return (string) $this->getRequest()->getParam('entity_type') === 'category' ? 'category' : 'product';
    }

    private function decodeJsonParam(string $param): array
    {
        $decoded = json_decode((string) $this->getRequest()->getParam($param, '[]'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getRequestStoreId(): int
    {
        return max(0, (int) $this->getRequest()->getParam('store', 0));
    }

    private function getProductIdBySku(string $sku): ?int
    {
        if ($sku === '') {
            return null;
        }
        try {
            return (int) $this->productRepository->get($sku, false, $this->getRequestStoreId(), true)->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getContextImageUrl(array $productData): string
    {
        return isset($productData['_image_payload']['value']) ? trim((string) $productData['_image_payload']['value']) : '';
    }

    private function getProductImagePayload($product): string
    {
        $imageFile = trim((string) $product->getData('image'));
        if ($imageFile === '' || $imageFile === 'no_selection') {
            $gallery = $product->getMediaGalleryImages();
            if ($gallery && $gallery->getSize()) {
                $imageFile = (string) $gallery->getFirstItem()->getFile();
            }
        }
        if ($imageFile === '' || $imageFile === 'no_selection') {
            return '';
        }
        $relativePath = ltrim($imageFile, '/');
        $path = $this->directoryList->getPath(DirectoryList::MEDIA) . '/catalog/product/' . $relativePath;
        if (is_readable($path)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
            return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($path));
        }
        return $this->storeManager->getStore($this->getRequestStoreId())->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product/' . $relativePath;
    }

    private function normalizePromptValue(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return strlen($value) > 1000 ? substr($value, 0, 1000) . '...' : $value;
    }

    private function shouldIncludePromptField(string $code, string $label, string $value): bool
    {
        if (!$this->hasUsefulPromptValue($value)) {
            return false;
        }
        if (in_array($code, ['entity_id', 'attribute_set_id', 'store_id', 'has_options', 'required_options', 'created_at', 'updated_at', 'tier_price_changed', 'is_salable', 'image', 'small_image', 'thumbnail', 'swatch_image', 'image_url', 'media_gallery', 'options_container', 'contentai_status', 'contentai_last_generated_at'], true)) {
            return false;
        }
        if (strpos($code, 'contentai_') === 0) {
            return false;
        }
        return true;
    }

    private function hasUsefulPromptValue(string $value): bool
    {
        $value = trim(strip_tags($value));
        if ($value === '' || $value === '-' || strtolower($value) === 'no_selection') {
            return false;
        }
        if (is_numeric($value) && (float) $value == 0.0) {
            return false;
        }
        return true;
    }
}
