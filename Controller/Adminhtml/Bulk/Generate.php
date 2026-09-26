<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Bulk;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
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
use Nistruct\ContentAI\Model\Eav\ProductAttributeValueResolver;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Field\ProductFieldRequestValidator;
use Nistruct\ContentAI\Model\Scope\GenerationContextResolver;
use Nistruct\ContentAI\Model\Scope\GenerationContext;
use Nistruct\ContentAI\Model\Prompt\EntityPromptBuilder;
use Psr\Log\LoggerInterface;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::bulk';

    private $collectionFactory;
    private $categoryCollectionFactory;
    private $productRepository;
    private $completions;
    private $helper;
    private $bulkReportFactory;
    private $storeManager;
    private $directoryList;
    private $logger;
    private ProductFieldProvider $fieldProvider;
    private ProductFieldRequestValidator $fieldRequestValidator;
    private ProductAttributeValueResolver $valueResolver;
    private GenerationContextResolver $contextResolver;
    private EntityPromptBuilder $promptBuilder;

    public function __construct(
        Action\Context $context,
        CollectionFactory $collectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductRepositoryInterface $productRepository,
        Completions $completions,
        HelperData $helper,
        BulkReportFactory $bulkReportFactory,
        StoreManagerInterface $storeManager,
        DirectoryList $directoryList,
        LoggerInterface $logger,
        ProductFieldProvider $fieldProvider,
        ProductFieldRequestValidator $fieldRequestValidator,
        ProductAttributeValueResolver $valueResolver,
        GenerationContextResolver $contextResolver,
        EntityPromptBuilder $promptBuilder
    ) {
        parent::__construct($context);
        $this->collectionFactory = $collectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productRepository = $productRepository;
        $this->completions = $completions;
        $this->helper = $helper;
        $this->bulkReportFactory = $bulkReportFactory;
        $this->storeManager = $storeManager;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
        $this->fieldProvider = $fieldProvider;
        $this->fieldRequestValidator = $fieldRequestValidator;
        $this->valueResolver = $valueResolver;
        $this->contextResolver = $contextResolver;
        $this->promptBuilder = $promptBuilder;
    }

    public function execute()
    {
        if (!$this->helper->isEnabled()) {
            $this->messageManager->addErrorMessage(__('ContentAI is disabled.'));
            return $this->_redirect('*/*/index');
        }

        $fields = $this->getRequestedFields('fields');
        if (!$fields) {
            $this->messageManager->addErrorMessage(__('Select at least one field to generate.'));
            return $this->_redirect('*/*/index');
        }

        $targets = $this->getGenerationTargets();
        if (!$targets) {
            $this->messageManager->addErrorMessage(__('Select at least one store view.'));
            return $this->_redirect('*/*/index');
        }

        $source = (string)$this->getRequest()->getParam('product_source');
        $skus = $this->parseSkus((string)$this->getRequest()->getParam('skus', ''));
        $categoryId = (int)$this->getRequest()->getParam('category_id', 0);
        if (($source === 'category' && $categoryId <= 0) || ($source === 'skus' && !$skus)) {
            $this->messageManager->addErrorMessage(__('Choose a category or enter at least one SKU.'));
            return $this->_redirect('*/*/index');
        }
        if (!in_array($source, ['category', 'skus'], true)) {
            $this->messageManager->addErrorMessage(__('Choose how products should be selected.'));
            return $this->_redirect('*/*/index');
        }
        if ($source === 'category' && !$this->categorySelectionMatchesWebsites($categoryId)) {
            $this->messageManager->addErrorMessage(
                __('The selected category is not valid for every selected website root. Select websites that share this category tree, or use Specific SKUs.')
            );
            return $this->_redirect('*/*/index');
        }

        $generatedTotal = 0;
        $failedTotal = 0;
        $reportIds = [];
        foreach ($targets as $target) {
            $result = $this->generateForStore(
                $target['store_id'],
                $fields,
                $source,
                $categoryId,
                $skus,
                $target['website_ids'],
                $target['source_store_id']
            );
            $generatedTotal += $result['generated'];
            $failedTotal += $result['failed'];
            if ($result['report_id']) {
                $reportIds[] = $result['report_id'];
            }
        }

        if (!$reportIds) {
            $this->messageManager->addNoticeMessage(__('No products matched the selected filters in the selected store views.'));
            return $this->_redirect('*/*/index');
        }

        if ($generatedTotal) {
            $this->messageManager->addSuccessMessage(
                __('Bulk generation finished with %1 generated product result(s) across %2 store view report(s).', $generatedTotal, count($reportIds))
            );
        } else {
            $this->messageManager->addErrorMessage(__('No product content was generated. Check contentai.log.'));
        }
        if ($failedTotal) {
            $this->messageManager->addWarningMessage(__('%1 product generation(s) failed. Check contentai.log.', $failedTotal));
        }

        return $this->_redirect('nistruct_contentai/bulkreport/index');
    }

    private function generateForStore(
        int $storeId,
        array $fields,
        string $source,
        int $categoryId,
        array $skus,
        array $websiteIds,
        int $sourceStoreId
    ): array
    {
        $fields = array_values(array_filter($fields, function (string $code) use ($storeId): bool {
            $field = $this->fieldProvider->getField($code);
            return $field && !($storeId > 0 && $field['scope'] === 'global');
        }));
        if (!$fields) {
            return ['generated' => 0, 'failed' => 0, 'report_id' => null];
        }
        $collection = $this->buildProductCollection(
            $storeId,
            $fields,
            $source,
            $categoryId,
            $skus,
            $websiteIds,
            $sourceStoreId
        );
        $productIds = array_values(array_unique(array_map('intval', $collection->getAllIds())));
        if (!$productIds) {
            return ['generated' => 0, 'failed' => 0, 'report_id' => null];
        }

        if ((string)$this->getRequest()->getParam('content_scope', 'missing') === 'missing') {
            $productIds = array_values(array_filter($productIds, function (int $productId) use ($fields, $storeId): bool {
                $product=$this->productRepository->getById($productId, false, $storeId, true);
                $validFields=array_values(array_filter($fields, fn(string $code): bool => $this->fieldProvider->isFieldInAttributeSet($code, (int)$product->getAttributeSetId())));
                if (!$validFields) { return false; }
                foreach ($validFields as $code) {
                    if ($this->valueResolver->isMissingOwnValue($productId, $code, $storeId)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if (!$productIds) {
            return ['generated' => 0, 'failed' => 0, 'report_id' => null];
        }

        $context = $this->contextResolver->resolve($storeId, $websiteIds, $sourceStoreId);
        $language = $context->getLanguage();
        $fields = array_values($fields);

        $report = $this->bulkReportFactory->create();
        $report->setStoreId($storeId);
        $report->setAiData(json_encode([
            'fields' => $fields,
            'language' => $language,
            'products' => [],
            'context' => $context->toArray(),
        ], JSON_UNESCAPED_UNICODE));
        $report->setApprovalStatus(ReportStatus::PROCESSING);
        $report->setCreatedAt(date('Y-m-d H:i:s'));
        $report->save();

        $products = [];
        $failed = 0;
        foreach (array_chunk($productIds, $this->getAutomaticBatchSize(count($productIds))) as $batch) {
            foreach ($batch as $productId) {
                try {
                    $products[] = $this->generateQueuedProduct(
                        $productId,
                        $fields,
                        $context
                    );
                } catch (\Throwable $e) {
                    $failed++;
                    $products[] = [
                        'product_id' => $productId,
                        'sku' => '',
                        'fields' => [],
                        'approval_status' => ReportStatus::FAILED,
                        'applied_fields' => [],
                        'error' => $e->getMessage(),
                    ];
                    $this->logger->error('ContentAI bulk product generation failed: ' . $e->getMessage());
                }
            }
            $report->setAiData(json_encode([
                'fields' => $fields,
                'language' => $language,
                'products' => $products,
                'context' => $context->toArray(),
            ], JSON_UNESCAPED_UNICODE))->save();
        }

        $generated = count(array_filter($products, function (array $product): bool {
            return !empty($product['fields']);
        }));
        $report->setApprovalStatus($generated ? $this->getBatchStatus($products) : ReportStatus::FAILED)->save();

        return ['generated' => $generated, 'failed' => $failed, 'report_id' => (int)$report->getId()];
    }

    public function generateQueuedProduct(
        int $productId,
        array $fields,
        GenerationContext $context
    ): array {
        $product = $this->productRepository->getById($productId, false, $context->getTargetStoreId(), true);
        $fields = array_values(array_filter($fields, fn(string $code): bool => $this->fieldProvider->isFieldInAttributeSet($code, (int)$product->getAttributeSetId())));
        if (!$fields) { throw new LocalizedException(__('None of the selected fields belong to this product attribute set.')); }
        $prompt = $this->promptBuilder->build(
            'product',
            $this->buildSelectedFields($product, $fields),
            $this->buildProductData($product),
            $context
        );
        $imagePayload = $this->getProductImagePayload($product, $context->getSourceStoreId());

        $this->completions->resetUsageMetadata();
        $decoded = $this->decodeFieldsResponse($this->completions->generateContent($prompt, $imagePayload));
        $generated = $this->filterGeneratedFields($decoded, $fields);
        if (!$generated) {
            throw new LocalizedException(__('AI did not return valid generated fields.'));
        }

        return [
            'product_id' => (int)$product->getId(),
            'sku' => (string)$product->getSku(),
            'fields' => $generated,
            'approval_status' => ReportStatus::PENDING_APPROVAL,
            'applied_fields' => [],
        ];
    }

    private function buildProductCollection(
        int $storeId,
        array $fields,
        string $source,
        int $categoryId,
        array $skus,
        array $websiteIds,
        int $sourceStoreId
    )
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        if ($websiteIds) {
            $collection->addWebsiteFilter($websiteIds);
        }
        $collection->addAttributeToSelect('sku')
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

        if ($source === 'skus') {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        } else {
            $categoryIds = $this->getCategoryBranchIds($categoryId, $sourceStoreId);
            if (!$categoryIds) {
                $collection->addAttributeToFilter('entity_id', -1);
            } else {
                $collection->addCategoriesFilter(['in' => $categoryIds]);
            }
        }

        return $collection;
    }

    private function getCategoryBranchIds(int $categoryId, int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $rootCategoryId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
        $categoryCollection = $this->categoryCollectionFactory->create();
        $categoryCollection->setStoreId($storeId);
        $category = $categoryCollection
            ->addAttributeToSelect('is_active')
            ->addAttributeToFilter('is_active', 1)
            ->addFieldToFilter('entity_id', $categoryId)
            ->getFirstItem();
        $path = (string)$category->getPath();
        $rootPath = '1/' . $rootCategoryId;
        if ($path === '' || ($path !== $rootPath && strpos($path, $rootPath . '/') !== 0)) {
            return [];
        }

        $branch = $this->categoryCollectionFactory->create();
        $branch->setStoreId($storeId);
        $branch->addAttributeToSelect('is_active')
            ->addAttributeToFilter('is_active', 1)
            ->addFieldToFilter('path', [
                ['eq' => $path],
                ['like' => $path . '/%'],
            ]);

        return array_map('intval', $branch->getAllIds());
    }

    private function getRequestedFields(string $param): array
    {
        return $this->fieldRequestValidator->validateCodes(
            (array)$this->getRequest()->getParam($param, [])
        );
    }

    private function getGenerationTargets(): array
    {
        $selectedWebsiteIds = array_values(array_filter(array_unique(array_map(
            'intval',
            (array)$this->getRequest()->getParam('website_ids', [])
        ))));
        $websiteLookup = array_flip($selectedWebsiteIds);
        $storeIds = array_values(array_unique(array_map(
            'intval',
            (array)$this->getRequest()->getParam('store_ids', [])
        )));
        $targets = [];
        foreach ($storeIds as $storeId) {
            try {
                if ($storeId === 0) {
                    $sourceStoreId = $this->getDefaultStoreId($selectedWebsiteIds);
                    if ($sourceStoreId) {
                        $targets[] = [
                            'store_id' => 0,
                            'source_store_id' => $sourceStoreId,
                            'website_ids' => $selectedWebsiteIds,
                        ];
                    }
                    continue;
                }
                $store = $this->storeManager->getStore($storeId);
                if (isset($websiteLookup[(int)$store->getWebsiteId()])) {
                    $targets[] = [
                        'store_id' => $storeId,
                        'source_store_id' => $storeId,
                        'website_ids' => [(int)$store->getWebsiteId()],
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $targets;
    }

    private function getDefaultStoreId(array $websiteIds): int
    {
        foreach ($websiteIds as $websiteId) {
            try {
                $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
                if ($store) {
                    return (int)$store->getId();
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return 0;
    }

    private function categorySelectionMatchesWebsites(int $categoryId): bool
    {
        $websiteIds = array_values(array_filter(array_unique(array_map(
            'intval',
            (array)$this->getRequest()->getParam('website_ids', [])
        ))));
        $rootIds = [];
        foreach ($websiteIds as $websiteId) {
            try {
                $rootIds[] = (int)$this->storeManager->getWebsite($websiteId)->getDefaultStore()->getRootCategoryId();
            } catch (\Exception $e) {
                return false;
            }
        }
        $rootIds = array_values(array_unique($rootIds));
        if (count($rootIds) !== 1) {
            return false;
        }
        $category = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect('path')
            ->addFieldToFilter('entity_id', $categoryId)
            ->getFirstItem();
        $rootPath = '1/' . $rootIds[0];
        $path = (string)$category->getPath();
        return $path === $rootPath || strpos($path, $rootPath . '/') === 0;
    }

    private function getAutomaticBatchSize(int $productCount): int
    {
        if ($productCount <= 10) {
            return max(1, $productCount);
        }
        if ($productCount <= 50) {
            return 10;
        }
        if ($productCount <= 200) {
            return 20;
        }

        return 25;
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
            $field = $this->fieldProvider->getField($code);
            $selectedFields[] = [
                'code' => $code,
                'label' => $field['label'] ?? $code,
                'value' => (string) $product->getData($code),
                'allows_html' => !empty($field['allows_html']),
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

    private function filterGeneratedFields(array $fields, array $allowedFields): array
    {
        $allowed = array_flip($allowedFields);
        $generated = [];
        foreach ($fields as $code => $value) {
            $code = (string) $code;
            if (!isset($allowed[$code])) {
                $code = $this->normalizeFieldCode($code);
            }
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
        ];

        return $aliases[$code] ?? $code;
    }

    private function getProductImagePayload(Product $product, int $sourceStoreId): string
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

        return $this->storeManager->getStore($sourceStoreId)
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product/' . $relativePath;
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
