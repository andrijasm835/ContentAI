<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Query\Completions;
use Nistruct\ContentAI\Model\ReportFactory;
use Nistruct\ContentAI\Model\Apply\ProductAttributeApplyService;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Field\ProductFieldRequestValidator;
use Nistruct\ContentAI\Model\Field\CategoryFieldProvider;
use Nistruct\ContentAI\Model\Scope\GenerationContextResolver;
use Nistruct\ContentAI\Model\Prompt\EntityPromptBuilder;
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
    private $categoryRepository;
    private $categoryResource;
    private $cache;
    private ProductAttributeApplyService $productApplyService;
    private ProductFieldProvider $productFieldProvider;
    private ProductFieldRequestValidator $productFieldRequestValidator;
    private GenerationContextResolver $contextResolver;
    private CategoryFieldProvider $categoryFieldProvider;
    private EntityPromptBuilder $promptBuilder;

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
        CategoryRepositoryInterface $categoryRepository,
        CategoryResource $categoryResource,
        CacheInterface $cache,
        ProductAttributeApplyService $productApplyService,
        ProductFieldProvider $productFieldProvider,
        ProductFieldRequestValidator $productFieldRequestValidator,
        GenerationContextResolver $contextResolver,
        CategoryFieldProvider $categoryFieldProvider,
        EntityPromptBuilder $promptBuilder
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
        $this->categoryRepository = $categoryRepository;
        $this->categoryResource = $categoryResource;
        $this->cache = $cache;
        $this->productApplyService = $productApplyService;
        $this->productFieldProvider = $productFieldProvider;
        $this->productFieldRequestValidator = $productFieldRequestValidator;
        $this->contextResolver = $contextResolver;
        $this->categoryFieldProvider = $categoryFieldProvider;
        $this->promptBuilder = $promptBuilder;
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
        $this->productFieldRequestValidator->validateSelections($selectedFields);
        $productData = $this->decodeJsonParam('product_data');
        $sku = trim((string) $this->getRequest()->getParam('sku', ''));
        $storeId = $this->getRequestStoreId();
        $context = $this->contextResolver->resolve($storeId);
        $language = $context->getLanguage();
        $productData = $this->enrichProductData($productData, $sku);

        if ($sku !== '') {
            $product = $this->productRepository->get($sku, false, $storeId, true);
            $selectedFields = array_values(array_filter($selectedFields, function (array $field) use ($product, $storeId): bool {
                $metadata=$this->productFieldProvider->getField((string)($field['code'] ?? ''));
                return $metadata && !($storeId > 0 && $metadata['scope'] === 'global')
                    && $this->productFieldProvider->isFieldInAttributeSet((string)$field['code'], (int)$product->getAttributeSetId());
            }));
        }

        if (empty($selectedFields)) {
            return ['error' => true, 'data' => (string) __('No selected fields are allowed for this product, attribute set and store scope.')];
        }

        $prompt = $this->promptBuilder->build('product', $this->decorateProductFields($selectedFields), $productData, $context);
        $imagePayload = $this->getContextImageUrl($productData);
        $this->queryCompletion->resetUsageMetadata();
        $fields = $this->decodeFieldsResponse($this->queryCompletion->generateContent($prompt, $imagePayload));

        $allowedCodes = [];
        foreach ($selectedFields as $field) {
            if (!empty($field['code'])) {
                $allowedCodes[(string) $field['code']] = true;
            }
        }

        $filtered = [];
        foreach ($fields as $code => $value) {
            $code = (string) $code;
            if (!isset($allowedCodes[$code])) {
                $code = $this->normalizeReturnedFieldCode($code);
            }
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
            $report->setCreatedAt(date('Y-m-d H:i:s'));
            $report->setGeneratorType('single');
            $report->setData('usage_metadata', json_encode(['api' => $this->queryCompletion->getUsageMetadata(), 'context' => $context->toArray()], JSON_UNESCAPED_UNICODE));
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

        $saved = [];
        foreach ($fields as $code => $value) {
            $targetCode = (string)$code;
            if (!$this->productFieldProvider->getField($targetCode) || !is_scalar($value)) {
                continue;
            }
            $saved[$targetCode] = $this->helper->sanitizeHtml((string) $value);
        }

        if (empty($saved)) {
            return ['error' => true, 'data' => (string) __('No valid product fields selected for apply.')];
        }

        $result = $this->productApplyService->apply($sku, $saved, $storeId);
        $saved = $result['saved'];
        $this->logger->info(sprintf('ContentAI applied fields for SKU %s store %d: %s', $sku, $storeId, implode(',', array_keys($saved))));

        return ['error' => false, 'data' => ['fields' => $saved]];
    }

    private function generateSelectedCategoryFields(): array
    {
        $selectedFields = $this->decodeJsonParam('selected_fields');
        $categoryData = $this->decodeJsonParam('category_data');
        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId = $this->getRequestStoreId();
        $context = $this->contextResolver->resolve($storeId);
        $language = $context->getLanguage();

        if ($categoryId <= 0) {
            return ['error' => true, 'data' => (string) __('Category ID is missing.')];
        }
        if (empty($selectedFields)) {
            return ['error' => true, 'data' => (string) __('No fields selected.')];
        }

        $selectedFields=array_values(array_filter($selectedFields, function(array $field) use ($storeId): bool {
            $metadata=$this->categoryFieldProvider->getField((string)($field['code'] ?? ''));
            return $metadata && !($storeId > 0 && ($metadata['scope'] ?? 'store') === 'global');
        }));
        if (!$selectedFields) { return ['error'=>true,'data'=>(string)__('No selected category fields are allowed for this store scope.')]; }

        $category = $this->categoryRepository->get($categoryId, $storeId);
        $categoryData = $this->enrichCategoryData($categoryData, $category);
        $prompt = $this->promptBuilder->build('category', $selectedFields, $categoryData, $context);

        $this->queryCompletion->resetUsageMetadata();
        $fields = $this->decodeFieldsResponse($this->queryCompletion->generateContent($prompt));

        $allowedCodes = [];
        foreach ($selectedFields as $field) {
            if (!empty($field['code'])) {
                $allowedCodes[(string) $field['code']] = true;
            }
        }

        $filtered = [];
        foreach ($fields as $code => $value) {
            $code = (string)$code;
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
            $report->setCreatedAt(date('Y-m-d H:i:s'));
            $report->setGeneratorType('single');
            $report->setData('usage_metadata', json_encode(['api' => $this->queryCompletion->getUsageMetadata(), 'context' => $context->toArray()], JSON_UNESCAPED_UNICODE));
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
            $field = $this->categoryFieldProvider->getField($code);
            if (!$field || ($storeId > 0 && ($field['scope'] ?? 'store') === 'global') || !is_scalar($value)) {
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
        $category = $this->categoryRepository->get($categoryId, $storeId);
        $category->setStoreId($storeId);
        foreach ($values as $code => $value) {
            if (!$this->categoryResource->getAttribute($code)) {
                continue;
            }
            $category->setData($code, $value);
            $this->categoryResource->saveAttribute($category, $code);
        }
    }

    private function decorateProductFields(array $fields): array
    {
        foreach ($fields as &$field) {
            $metadata = $this->productFieldProvider->getField((string)($field['code'] ?? ''));
            $field['allows_html'] = !empty($metadata['allows_html']);
        }
        unset($field);
        return $fields;
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

        $aliases = ['meta_keywords' => 'meta_keyword', 'keywords' => 'meta_keyword'];
        return $aliases[$code] ?? $code;
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
