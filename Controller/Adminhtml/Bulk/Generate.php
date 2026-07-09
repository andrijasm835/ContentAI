<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Bulk;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\BulkReportFactory;
use Nistruct\ContentAI\Model\Query\Completions;
use Nistruct\ContentAI\Model\ReportStatus;
use Psr\Log\LoggerInterface;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::bulk';

    private const FIELD_LABELS = [
        'subtitle' => 'Subtitle',
        'features' => 'Features',
        'short_description' => 'Short Description',
        'description' => 'Description',
        'meta_title' => 'Meta Title',
        'meta_keyword' => 'Meta Keywords',
        'meta_description' => 'Meta Description',
        'image_label' => 'Base Image Label',
        'small_image_label' => 'Small Image Label',
        'thumbnail_label' => 'Thumbnail Label',
    ];

    private const FIELD_TARGETS = [
        'subtitle' => 'product_subtitle',
        'features' => 'tech_specs_features',
        'short_description' => 'short_description',
        'description' => 'description',
        'meta_title' => 'meta_title',
        'meta_keyword' => 'meta_keyword',
        'meta_description' => 'meta_description',
        'image_label' => 'image_label',
        'small_image_label' => 'small_image_label',
        'thumbnail_label' => 'thumbnail_label',
    ];

    private $collectionFactory;
    private $productRepository;
    private $completions;
    private $helper;
    private $bulkReportFactory;
    private $storeManager;
    private $directoryList;
    private $logger;

    public function __construct(
        Action\Context $context,
        CollectionFactory $collectionFactory,
        ProductRepositoryInterface $productRepository,
        Completions $completions,
        HelperData $helper,
        BulkReportFactory $bulkReportFactory,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->collectionFactory = $collectionFactory;
        $this->productRepository = $productRepository;
        $this->completions = $completions;
        $this->helper = $helper;
        $this->bulkReportFactory = $bulkReportFactory;
        $this->storeManager = $storeManager;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
    }

    public function execute()
    {
        if (!$this->helper->isEnabled()) {
            $this->messageManager->addErrorMessage(__('ContentAI is disabled.'));
            return $this->_redirect('*/*/index');
        }

        $storeId = max(0, (int) $this->getRequest()->getParam('store_id', 0));
        $fields = $this->getRequestedFields('fields');
        if (!$fields) {
            $this->messageManager->addErrorMessage(__('Select at least one field to generate.'));
            return $this->_redirect('*/*/index');
        }

        $limit = max(1, min(50, (int) $this->getRequest()->getParam('limit', 10)));
        $collection = $this->buildProductCollection($storeId, $limit);
        if (!$collection->getSize()) {
            $this->messageManager->addNoticeMessage(__('No products matched the selected filters.'));
            return $this->_redirect('*/*/index');
        }

        $language = $this->helper->getLanguageByStoreId($storeId);
        $products = [];
        $usageMetadata = [];
        $failed = 0;
        $fields = array_values($fields);

        $report = $this->bulkReportFactory->create();
        $report->setStoreId($storeId);
        $report->setAiData(json_encode([
            'fields' => $fields,
            'language' => $language,
            'products' => [],
        ], JSON_UNESCAPED_UNICODE));
        $report->setData('usage_metadata', json_encode([]));
        $report->setApprovalStatus(ReportStatus::PROCESSING);
        $report->setCreatedAt(date('Y-m-d H:i:s'));
        $report->save();

        foreach ($collection as $collectionProduct) {
            try {
                $product = $this->productRepository->getById((int) $collectionProduct->getId(), false, $storeId, true);
                $selectedFields = $this->buildSelectedFields($product, $fields);
                $productData = $this->buildProductData($product);
                $imagePayload = $this->getProductImagePayload($product);
                $prompt = $this->buildPrompt($selectedFields, $productData, $language);

                $this->completions->resetUsageMetadata();
                $decoded = $this->decodeFieldsResponse($this->completions->generateContent($prompt, $imagePayload));
                $decoded = $this->retryWrongLanguageResponse($decoded, $prompt, $language, $imagePayload);
                $productUsage = $this->completions->getUsageMetadata();
                $generated = $this->filterGeneratedFields($decoded, $fields);

                if (!$generated) {
                    throw new LocalizedException(__('AI did not return valid generated fields.'));
                }

                $usageMetadata = $this->mergeUsageMetadata($usageMetadata, $productUsage);
                $products[] = [
                    'product_id' => (int) $product->getId(),
                    'sku' => (string) $product->getSku(),
                    'fields' => $generated,
                    'approval_status' => ReportStatus::PENDING_APPROVAL,
                    'applied_fields' => [],
                    'usage_metadata' => $productUsage,
                ];
            } catch (\Exception $e) {
                $failed++;
                $products[] = [
                    'product_id' => (int) $collectionProduct->getId(),
                    'sku' => (string) $collectionProduct->getSku(),
                    'fields' => [],
                    'approval_status' => 'failed',
                    'applied_fields' => [],
                    'error' => $e->getMessage(),
                ];
                $this->logger->error('ContentAI bulk generate failed: ' . $e->getMessage());
            }

            $report->setAiData(json_encode([
                'fields' => $fields,
                'language' => $language,
                'products' => $products,
            ], JSON_UNESCAPED_UNICODE));
            $report->setData('usage_metadata', json_encode($usageMetadata));
            $report->save();
        }

        $generatedCount = count(array_filter($products, function ($product) {
            return !empty($product['fields']);
        }));

        if (!$generatedCount) {
            $report->setApprovalStatus(ReportStatus::FAILED);
            $report->save();
            $this->messageManager->addErrorMessage(__('No product content was generated. Check contentai.log.'));
            return $this->_redirect('nistruct_contentai/bulkreport/view', ['id' => $report->getId()]);
        }

        $report->setAiData(json_encode([
            'fields' => $fields,
            'language' => $language,
            'products' => $products,
        ], JSON_UNESCAPED_UNICODE));
        $report->setData('usage_metadata', json_encode($usageMetadata));
        $report->setApprovalStatus($this->getBatchStatus($products));
        $report->save();

        $this->messageManager->addSuccessMessage(
            __('Bulk generation finished and report #%1 was created with %2 generated product(s). Open the report to review and apply fields.', $report->getId(), $generatedCount)
        );
        if ($failed) {
            $this->messageManager->addWarningMessage(__('%1 product(s) failed during generation. Check contentai.log.', $failed));
        }

        return $this->_redirect('nistruct_contentai/bulkreport/index');
    }

    private function buildProductCollection(int $storeId, int $limit)
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        $collection->addAttributeToSelect('*')
            ->setPageSize($limit)
            ->setCurPage(1);

        $skus = $this->parseSkus((string) $this->getRequest()->getParam('skus', ''));
        if ($skus) {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        }

        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        if ($categoryId > 0) {
            $collection->addCategoriesFilter(['in' => [$categoryId]]);
        }

        foreach ($this->getRequestedFields('missing_fields') as $code) {
            $target = self::FIELD_TARGETS[$code] ?? $code;
            $collection->addAttributeToFilter([
                ['attribute' => $target, 'null' => true],
                ['attribute' => $target, 'eq' => ''],
            ], null, 'left');
        }

        return $collection;
    }

    private function getRequestedFields(string $param): array
    {
        $values = (array) $this->getRequest()->getParam($param, []);
        $fields = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (isset(self::FIELD_LABELS[$value])) {
                $fields[] = $value;
            }
        }
        return array_values(array_unique($fields));
    }

    private function parseSkus(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    private function buildSelectedFields(Product $product, array $fields): array
    {
        $selectedFields = [];
        foreach ($fields as $code) {
            $target = self::FIELD_TARGETS[$code] ?? $code;
            $selectedFields[] = [
                'code' => $code,
                'label' => self::FIELD_LABELS[$code] ?? $code,
                'value' => (string) $product->getData($target),
            ];
        }
        return $selectedFields;
    }

    private function buildProductData(Product $product): array
    {
        $data = [];
        foreach ($product->getData() as $code => $value) {
            if (!is_scalar($value) || !$this->hasUsefulPromptValue((string) $value)) {
                continue;
            }

            $attribute = $product->getResource()->getAttribute((string) $code);
            $label = $attribute && $attribute->getFrontendLabel() ? (string) $attribute->getFrontendLabel() : (string) $code;
            if (!$this->shouldIncludePromptField((string) $code, $label, (string) $value)) {
                continue;
            }

            $data[$code] = [
                'label' => $label,
                'value' => $this->getReadableAttributeValue($product, (string) $code, (string) $value),
            ];
        }
        return $data;
    }

    private function getReadableAttributeValue(Product $product, string $code, string $value): string
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

    private function buildPrompt(array $selectedFields, array $productData, string $language): string
    {
        $lines = [
            'Generate improved Magento product field values for the selected output fields.',
            'The target language is determined only by the Magento store view/scope, not by the language of existing product data.',
            'Target language: ' . $language . '.',
            'All generated field values must be written strictly in the target language.',
            'Use current product data only as factual source material. Do not use it as the language, tone, or final wording authority.',
            'If current product data is in a different language, extract the facts and write fresh improved content in the target language. Do not produce a literal translation and do not return the same text unchanged.',
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
            'For meta_keyword return comma-separated keywords in the target language, except brand names, SKU values, and established product names.',
            'For image label fields return concise plain text describing the product image for accessibility and image context.',
            'If an image is provided as part of the message, use it as visual context for product appearance, shape, style, color, and visible product features.',
            '',
            'Selected fields:',
        ];

        foreach ($selectedFields as $field) {
            $lines[] = sprintf(
                '- %s (%s), current value: %s',
                (string) $field['label'],
                (string) $field['code'],
                $this->normalizePromptValue((string) $field['value'])
            );
        }

        $lines[] = '';
        $lines[] = 'Current product data:';
        foreach ($productData as $code => $field) {
            $lines[] = sprintf(
                '- %s (%s): %s',
                (string) $field['label'],
                (string) $code,
                $this->normalizePromptValue((string) $field['value'])
            );
        }

        $lines[] = '';
        $lines[] = 'Return format example: {"name":"New product name","meta_title":"New meta title"}';

        return implode("\n", $lines);
    }

    private function filterGeneratedFields(array $fields, array $allowedFields): array
    {
        $allowed = array_flip($allowedFields);
        $generated = [];
        foreach ($fields as $code => $value) {
            $code = $this->normalizeFieldCode((string) $code);
            if (isset($allowed[$code]) && is_scalar($value)) {
                $generated[$code] = $this->helper->sanitizeHtml((string) $value);
            }
        }
        return $generated;
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

    private function normalizeFieldCode(string $code): string
    {
        $aliases = [
            'meta_keywords' => 'meta_keyword',
            'keywords' => 'meta_keyword',
            'feature' => 'features',
            'technical_features' => 'features',
            'product_subtitle' => 'subtitle',
            'tech_specs_features' => 'features',
        ];

        return $aliases[$code] ?? $code;
    }

    private function retryWrongLanguageResponse(array $fields, string $prompt, string $language, string $imagePayload): array
    {
        if (stripos($language, 'english') === false || !$this->looksLikeSerbianCroatianBosnian($fields)) {
            return $fields;
        }

        $this->logger->warning('ContentAI detected non-English generated response for English target language. Retrying once.');
        try {
            return $this->decodeFieldsResponse($this->completions->generateContent(
                $prompt . "\n\nRegenerate in English only. Do not copy Serbian, Croatian, or Bosnian wording.",
                $imagePayload
            ));
        } catch (\Exception $e) {
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

    private function getProductImagePayload(Product $product): string
    {
        $image = trim((string) $product->getData('image'));
        if ($image === '' || $image === 'no_selection') {
            $gallery = $product->getMediaGalleryImages();
            if ($gallery && $gallery->getSize()) {
                $image = (string) $gallery->getFirstItem()->getFile();
            }
        }
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        $relativePath = ltrim($image, '/');
        $path = $this->directoryList->getPath(DirectoryList::MEDIA) . '/catalog/product/' . $relativePath;
        if (is_readable($path)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'image/jpeg';
            return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($path));
        }

        return $this->storeManager->getStore((int) $this->getRequest()->getParam('store_id', 0))
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product/' . $relativePath;
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

        $excludedFields = [
            'entity_id',
            'attribute_set_id',
            'store_id',
            'has_options',
            'required_options',
            'created_at',
            'updated_at',
            'tier_price_changed',
            'is_salable',
            'image',
            'small_image',
            'thumbnail',
            'swatch_image',
            'image_url',
            'media_gallery',
            'options_container',
            'contentai_status',
            'contentai_last_generated_at',
        ];

        return !in_array($code, $excludedFields, true) && strpos($code, 'contentai_') !== 0;
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

    private function mergeUsageMetadata(array $current, array $new): array
    {
        if (!$new) {
            return $current;
        }
        if (!$current) {
            return $new;
        }

        return [
            'provider' => (string) ($current['provider'] ?? $new['provider'] ?? ''),
            'model' => (string) ($current['model'] ?? $new['model'] ?? ''),
            'input_tokens' => (int) ($current['input_tokens'] ?? 0) + (int) ($new['input_tokens'] ?? 0),
            'output_tokens' => (int) ($current['output_tokens'] ?? 0) + (int) ($new['output_tokens'] ?? 0),
            'total_tokens' => (int) ($current['total_tokens'] ?? 0) + (int) ($new['total_tokens'] ?? 0),
            'estimated_cost' => (float) ($current['estimated_cost'] ?? 0) + (float) ($new['estimated_cost'] ?? 0),
            'currency' => (string) ($current['currency'] ?? $new['currency'] ?? 'USD'),
        ];
    }

    private function getBatchStatus(array $products): string
    {
        foreach ($products as $product) {
            if (!empty($product['fields']) && ($product['approval_status'] ?? '') !== ReportStatus::APPLIED) {
                return ReportStatus::PENDING_APPROVAL;
            }
        }
        return ReportStatus::APPLIED;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
