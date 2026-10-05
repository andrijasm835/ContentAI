<?php
namespace Nistruct\ContentAI\Model\Seo;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class Analyzer
{
    private const SEVERITY_CRITICAL = 'critical';
    private const SEVERITY_WARNING = 'warning';
    private const SEVERITY_NOTICE = 'notice';
    private const STATUS_OK = 'ok';
    private const STATUS_NO_MATCHES = 'no_matches';
    private const STATUS_NO_ISSUES = 'no_issues';
    private const CATEGORY_CONTENT = 'content';
    private const CATEGORY_URL = 'url';
    private const CATEGORY_STATUS = 'status';
    private const CATEGORY_OPTIONAL = 'optional';
    private const AI_FIXABLE_CODES = [
        'missing_meta_title' => true,
        'long_meta_title' => true,
        'short_meta_title' => true,
        'duplicate_meta_title' => true,
        'missing_meta_description' => true,
        'long_meta_description' => true,
        'short_meta_description' => true,
        'duplicate_meta_description' => true,
    ];
    private const ISSUE_LABELS = [
        'missing_meta_title' => 'Missing meta title',
        'long_meta_title' => 'Meta title is too long',
        'short_meta_title' => 'Meta title is too short',
        'duplicate_meta_title' => 'Duplicate meta title',
        'missing_meta_description' => 'Missing meta description',
        'long_meta_description' => 'Meta description is too long',
        'short_meta_description' => 'Meta description is too short',
        'duplicate_meta_description' => 'Duplicate meta description',
        'missing_url_key' => 'Missing URL key',
        'bad_url_key_format' => 'Suspicious URL key format',
        'duplicate_request_path' => 'Duplicate request path',
        'empty_request_path' => 'Empty request path',
        'empty_target_path' => 'Empty target path',
        'self_target' => 'Request path points to itself',
        'legacy_redirect_path_format' => 'Legacy redirect path format is suspicious',
        'bad_request_path_format' => 'Request path format is suspicious',
        'missing_active_rewrite' => 'Missing active URL',
        'inactive_entity_direct_rewrite' => 'Inactive item has an active URL',
        'multiple_active_rewrites' => 'Multiple active URLs',
        'legacy_redirects_present' => 'Legacy redirects present',
        'disabled_product' => 'Product is disabled',
        'inactive_category' => 'Category is inactive',
        'inactive_cms_page' => 'CMS page is inactive',
    ];
    private const ISSUE_CATEGORIES = [
        'missing_meta_title' => self::CATEGORY_CONTENT,
        'long_meta_title' => self::CATEGORY_CONTENT,
        'short_meta_title' => self::CATEGORY_CONTENT,
        'duplicate_meta_title' => self::CATEGORY_CONTENT,
        'missing_meta_description' => self::CATEGORY_CONTENT,
        'long_meta_description' => self::CATEGORY_CONTENT,
        'short_meta_description' => self::CATEGORY_CONTENT,
        'duplicate_meta_description' => self::CATEGORY_CONTENT,
        'missing_url_key' => self::CATEGORY_URL,
        'bad_url_key_format' => self::CATEGORY_URL,
        'duplicate_request_path' => self::CATEGORY_URL,
        'empty_request_path' => self::CATEGORY_URL,
        'empty_target_path' => self::CATEGORY_URL,
        'self_target' => self::CATEGORY_URL,
        'legacy_redirect_path_format' => self::CATEGORY_URL,
        'bad_request_path_format' => self::CATEGORY_URL,
        'missing_active_rewrite' => self::CATEGORY_URL,
        'inactive_entity_direct_rewrite' => self::CATEGORY_URL,
        'multiple_active_rewrites' => self::CATEGORY_URL,
        'legacy_redirects_present' => self::CATEGORY_URL,
        'disabled_product' => self::CATEGORY_STATUS,
        'inactive_category' => self::CATEGORY_STATUS,
        'inactive_cms_page' => self::CATEGORY_STATUS,
    ];

    private ProductCollectionFactory $productCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private PageCollectionFactory $pageCollectionFactory;
    private ResourceConnection $resourceConnection;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        PageCollectionFactory $pageCollectionFactory,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
    }

    public function analyze(string $scope, int $storeId, int $limit, int $offset = 0, array $filters = []): array
    {
        if ($storeId <= 0) {
            throw new LocalizedException(__('Select a concrete store view for the SEO audit.'));
        }
        $this->storeManager->getStore($storeId);
        $scope = in_array($scope, ['all', 'products', 'categories', 'cms'], true) ? $scope : 'all';
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $offset = (int) (floor($offset / $limit) * $limit);
        $filters = $this->normalizeFilters($filters);
        $sections = [];
        $duplicatePaths = $this->getDuplicateRequestPaths($storeId);

        if ($scope === 'all' || $scope === 'products') {
            $sections['products'] = $this->analyzeProducts($storeId, $limit, $offset, $duplicatePaths, $filters);
        }
        if ($scope === 'all' || $scope === 'categories') {
            $sections['categories'] = $this->analyzeCategories($storeId, $limit, $offset, $duplicatePaths, $filters);
        }
        if ($scope === 'all' || $scope === 'cms') {
            $sections['cms'] = $this->analyzeCmsPages($storeId, $limit, $offset, $duplicatePaths, $filters);
        }

        return $this->summarize($scope, $storeId, $limit, $offset, $filters, $sections);
    }

    private function analyzeProducts(int $storeId, int $limit, int $offset, array $duplicatePaths, array $filters): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'sku', 'status', 'url_key', 'meta_title', 'meta_keyword', 'meta_description'])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($limit)
            ->setCurPage($this->getPageFromOffset($limit, $offset));

        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        if ($filters['product_category_ids']) {
            $collection->addCategoriesFilter(['in' => $filters['product_category_ids']]);
        }
        if ($filters['product_skus']) {
            $collection->addAttributeToFilter('sku', ['in' => $filters['product_skus']]);
        }
        if ($filters['product_status'] !== '') {
            $collection->addAttributeToFilter('status', (int) $filters['product_status']);
        }
        $this->applyMissingAttributeFilters($collection, $filters['product_missing_fields'], [
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keyword' => 'meta_keyword',
            'url_key' => 'url_key',
        ]);

        $items = [];
        $metaTitles = [];
        $metaDescriptions = [];
        foreach ($collection as $product) {
            $identifier = (string) $product->getSku();
            $name = (string) $product->getName();
            $issues = $this->analyzeSeoFields(
                'product',
                $identifier,
                $name,
                (string) $product->getData('url_key'),
                (string) $product->getData('meta_title'),
                (string) $product->getData('meta_description'),
                (string) $product->getData('meta_keyword')
            );

            if ((int) $product->getData('status') !== Status::STATUS_ENABLED) {
                $issues[] = $this->issue(self::SEVERITY_NOTICE, 'disabled_product', 'Product is disabled.', 'Keep disabled product rewrites under review and remove obsolete public URLs when no redirect is needed.');
            }

            $rewrites = $this->getEntityRewrites('product', (int) $product->getId(), $storeId);
            $issues = array_merge(
                $issues,
                $this->analyzeEntityRewrites($rewrites, 'product', (int) $product->getData('status') === Status::STATUS_ENABLED, $duplicatePaths, $storeId > 0)
            );

            $metaTitles[] = ['value' => (string) $product->getData('meta_title'), 'identifier' => $identifier];
            $metaDescriptions[] = ['value' => (string) $product->getData('meta_description'), 'identifier' => $identifier];
            $items[] = $this->item('product', $identifier, $name, $issues, [
                'entity_id' => (string) $product->getId(),
                'rewrites' => $rewrites,
            ]);
        }

        $this->appendDuplicateIssues($items, $metaTitles, 'duplicate_meta_title', 'Duplicate meta title in scanned products.');
        $this->appendDuplicateIssues($items, $metaDescriptions, 'duplicate_meta_description', 'Duplicate meta description in scanned products.');
        return $this->section('products', 'Products', $items, $collection->getSize(), $offset, $filters, $storeId);
    }

    private function analyzeCategories(int $storeId, int $limit, int $offset, array $duplicatePaths, array $filters): array
    {
        $rootCategoryId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active', 'url_key', 'meta_title', 'meta_keywords', 'meta_description'])
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->addFieldToFilter('path', ['like' => '1/' . $rootCategoryId . '/%'])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($limit)
            ->setCurPage($this->getPageFromOffset($limit, $offset));
        if ($filters['category_ids']) {
            $collection->addAttributeToFilter('entity_id', ['in' => $filters['category_ids']]);
        }
        if ($filters['category_active'] !== '') {
            $collection->addAttributeToFilter('is_active', (int) $filters['category_active']);
        }
        $this->applyMissingAttributeFilters($collection, $filters['category_missing_fields'], [
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keywords' => 'meta_keywords',
            'url_key' => 'url_key',
        ]);

        $items = [];
        $metaTitles = [];
        $metaDescriptions = [];
        foreach ($collection as $category) {
            $identifier = (string) $category->getId();
            $name = (string) $category->getName();
            $issues = $this->analyzeSeoFields(
                'category',
                $identifier,
                $name,
                (string) $category->getData('url_key'),
                (string) $category->getData('meta_title'),
                (string) $category->getData('meta_description'),
                (string) $category->getData('meta_keywords')
            );

            if (!(bool) $category->getData('is_active')) {
                $issues[] = $this->issue(self::SEVERITY_NOTICE, 'inactive_category', 'Category is inactive.', 'Review whether inactive category URL rewrites should remain available.');
            }

            $rewrites = $this->getEntityRewrites('category', (int) $category->getId(), $storeId);
            $issues = array_merge(
                $issues,
                $this->analyzeEntityRewrites($rewrites, 'category', (bool) $category->getData('is_active'), $duplicatePaths, $storeId > 0)
            );

            $metaTitles[] = ['value' => (string) $category->getData('meta_title'), 'identifier' => $identifier];
            $metaDescriptions[] = ['value' => (string) $category->getData('meta_description'), 'identifier' => $identifier];
            $items[] = $this->item('category', $identifier, $name, $issues, [
                'entity_id' => (string) $category->getId(),
                'rewrites' => $rewrites,
            ]);
        }

        $this->appendDuplicateIssues($items, $metaTitles, 'duplicate_meta_title', 'Duplicate meta title in scanned categories.');
        $this->appendDuplicateIssues($items, $metaDescriptions, 'duplicate_meta_description', 'Duplicate meta description in scanned categories.');
        return $this->section('categories', 'Categories', $items, $collection->getSize(), $offset, $filters, $storeId);
    }

    private function analyzeCmsPages(int $storeId, int $limit, int $offset, array $duplicatePaths, array $filters): array
    {
        $collection = $this->pageCollectionFactory->create();
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        if ($filters['cms_page_ids']) {
            $collection->addFieldToFilter('page_id', ['in' => $filters['cms_page_ids']]);
        }
        if ($filters['cms_active'] !== '') {
            $collection->addFieldToFilter('is_active', (int) $filters['cms_active']);
        }
        $this->applyMissingFieldFilters($collection, $filters['cms_missing_fields'], [
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keywords' => 'meta_keywords',
            'identifier' => 'identifier',
        ]);
        $collection->setOrder('page_id', 'ASC')
            ->setPageSize($limit)
            ->setCurPage($this->getPageFromOffset($limit, $offset));

        $items = [];
        $metaTitles = [];
        $metaDescriptions = [];
        foreach ($collection as $page) {
            $identifier = (string) $page->getIdentifier();
            $title = (string) $page->getTitle();
            $issues = $this->analyzeSeoFields(
                'cms_page',
                $identifier,
                $title,
                $identifier,
                (string) $page->getMetaTitle(),
                (string) $page->getMetaDescription(),
                (string) $page->getMetaKeywords()
            );

            if (!(bool) $page->getIsActive()) {
                $issues[] = $this->issue(self::SEVERITY_NOTICE, 'inactive_cms_page', 'CMS page is inactive.', 'Review whether inactive CMS page URL should remain indexed or redirected.');
            }

            $rewrites = $this->getEntityRewrites('cms-page', (int) $page->getId(), $storeId);
            $issues = array_merge(
                $issues,
                $this->analyzeEntityRewrites($rewrites, 'cms page', (bool) $page->getIsActive(), $duplicatePaths, false)
            );

            $metaTitles[] = ['value' => (string) $page->getMetaTitle(), 'identifier' => $identifier];
            $metaDescriptions[] = ['value' => (string) $page->getMetaDescription(), 'identifier' => $identifier];
            $items[] = $this->item('cms_page', $identifier, $title, $issues, [
                'entity_id' => (string) $page->getId(),
                'rewrites' => $rewrites,
            ]);
        }

        $this->appendDuplicateIssues($items, $metaTitles, 'duplicate_meta_title', 'Duplicate meta title in scanned CMS pages.');
        $this->appendDuplicateIssues($items, $metaDescriptions, 'duplicate_meta_description', 'Duplicate meta description in scanned CMS pages.');
        return $this->section('cms', 'CMS Pages', $items, $collection->getSize(), $offset, $filters, $storeId);
    }

    private function getDuplicateRequestPaths(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rewriteTable = $this->resourceConnection->getTableName('url_rewrite');

        return $connection->fetchPairs(
            $connection->select()
                ->from($rewriteTable, ['request_path', 'cnt' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id = ?', $storeId)
                ->group('request_path')
                ->having('COUNT(*) > 1')
        );
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'product_category_ids' => $this->normalizeIds($filters['product_category_ids'] ?? []),
            'product_skus' => $this->normalizeSkus($filters['product_skus'] ?? []),
            'product_status' => $this->normalizeOptionalStatus($filters['product_status'] ?? ''),
            'product_missing_fields' => $this->normalizeFieldCodes($filters['product_missing_fields'] ?? []),
            'category_ids' => $this->normalizeIds($filters['category_ids'] ?? []),
            'category_active' => $this->normalizeOptionalStatus($filters['category_active'] ?? ''),
            'category_missing_fields' => $this->normalizeFieldCodes($filters['category_missing_fields'] ?? []),
            'cms_page_ids' => $this->normalizeIds($filters['cms_page_ids'] ?? []),
            'cms_active' => $this->normalizeOptionalStatus($filters['cms_active'] ?? ''),
            'cms_missing_fields' => $this->normalizeFieldCodes($filters['cms_missing_fields'] ?? []),
        ];
    }

    private function normalizeIds($value): array
    {
        if (!is_array($value)) {
            $value = preg_split('/[\s,]+/', (string) $value) ?: [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function normalizeSkus($value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $skus = [];
        foreach (preg_split('/[\s,]+/', (string) $value) ?: [] as $sku) {
            $sku = trim($sku);
            if ($sku !== '') {
                $skus[$sku] = $sku;
            }
        }

        return array_values($skus);
    }

    private function normalizeFieldCodes($value): array
    {
        if (!is_array($value)) {
            $value = preg_split('/[\s,]+/', (string) $value) ?: [];
        }

        $fields = [];
        foreach ($value as $field) {
            $field = trim((string) $field);
            if ($field !== '') {
                $fields[$field] = $field;
            }
        }

        return array_values($fields);
    }

    private function normalizeOptionalStatus($value): string
    {
        $value = trim((string) $value);
        return in_array($value, ['0', '1', '2'], true) ? $value : '';
    }

    private function applyMissingAttributeFilters($collection, array $selectedFields, array $allowedFields): void
    {
        $conditions = [];
        foreach ($selectedFields as $field) {
            if (!isset($allowedFields[$field])) {
                continue;
            }
            $attribute = $allowedFields[$field];
            $conditions[] = ['attribute' => $attribute, 'null' => true];
            $conditions[] = ['attribute' => $attribute, 'eq' => ''];
        }

        if ($conditions) {
            $collection->addAttributeToFilter($conditions);
        }
    }

    private function applyMissingFieldFilters($collection, array $selectedFields, array $allowedFields): void
    {
        $connection = $this->resourceConnection->getConnection();
        $conditions = [];
        foreach ($selectedFields as $field) {
            if (!isset($allowedFields[$field])) {
                continue;
            }
            $fieldName = $connection->quoteIdentifier($allowedFields[$field]);
            $conditions[] = $fieldName . ' IS NULL';
            $conditions[] = $fieldName . " = ''";
        }

        if ($conditions) {
            $collection->getSelect()->where('(' . implode(' OR ', $conditions) . ')');
        }
    }

    private function getEntityRewrites(string $entityType, int $entityId, int $storeId): array
    {
        if ($entityId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rewriteTable = $this->resourceConnection->getTableName('url_rewrite');
        $select = $connection->select()
            ->from(
                ['u' => $rewriteTable],
                ['url_rewrite_id', 'request_path', 'target_path', 'redirect_type', 'is_autogenerated', 'description']
            )
            ->where('u.store_id = ?', $storeId)
            ->where('u.entity_type = ?', $entityType)
            ->where('u.entity_id = ?', $entityId)
            ->order('u.redirect_type ASC')
            ->order('u.is_autogenerated DESC')
            ->order('u.url_rewrite_id DESC');

        return $connection->fetchAll($select);
    }

    private function analyzeEntityRewrites(array $rewrites, string $entityType, bool $isActive, array $duplicatePaths, bool $requiresDirectRewrite): array
    {
        $issues = [];
        $directCount = 0;
        $redirectCount = 0;

        foreach ($rewrites as $rewrite) {
            $requestPath = (string) ($rewrite['request_path'] ?? '');
            $targetPath = (string) ($rewrite['target_path'] ?? '');
            $redirectType = (int) ($rewrite['redirect_type'] ?? 0);

            if ($redirectType === 0) {
                $directCount++;
            } else {
                $redirectCount++;
            }

            if (isset($duplicatePaths[$requestPath])) {
                $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'duplicate_request_path', 'Duplicate request path.', 'Resolve duplicate URL rewrites so one public URL maps to one target per store view.');
            }
            if (trim($requestPath) === '') {
                $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'empty_request_path', 'Request path is empty.', 'Delete or regenerate this rewrite.');
            }
            if (trim($targetPath) === '') {
                $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'empty_target_path', 'Target path is empty.', 'Set a valid target path or remove the rewrite.');
            }
            if ($requestPath !== '' && $requestPath === $targetPath) {
                $issues[] = $this->issue(self::SEVERITY_WARNING, 'self_target', 'Request path equals target path.', 'Review this rewrite because it does not change the destination.');
            }
            if ($requestPath !== strtolower($requestPath) || preg_match('/\s|%20|\/\/|[?&#]/', $requestPath)) {
                $issues[] = $redirectType > 0
                    ? $this->issue(self::SEVERITY_NOTICE, 'legacy_redirect_path_format', 'Redirect request path format is suspicious.', 'This may be acceptable for legacy redirects, but review whether the old URL still needs to exist.')
                    : $this->issue(self::SEVERITY_WARNING, 'bad_request_path_format', 'Request path format is suspicious.', 'Use lowercase, hyphen-separated paths without spaces, query strings, duplicate slashes, or anchors.');
            }
        }

        if ($requiresDirectRewrite && $isActive && $directCount === 0) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'missing_active_rewrite', 'No active direct URL rewrite was found.', 'Review URL key and reindex/regenerate URL rewrites so this ' . $entityType . ' has a current public URL.');
        }
        if (!$isActive && $directCount > 0) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'inactive_entity_direct_rewrite', 'Inactive entity still has an active direct URL rewrite.', 'Remove the direct rewrite or replace it with a deliberate redirect if the old URL has SEO value.');
        }
        if ($directCount > 1) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'multiple_active_rewrites', 'Multiple active direct URL rewrites were found.', 'Keep one current public URL and redirect or remove the extras.');
        }
        if ($redirectCount > 0) {
            $issues[] = $this->issue(self::SEVERITY_NOTICE, 'legacy_redirects_present', 'Legacy redirect URL rewrite(s) are present.', 'Keep only redirects that still receive traffic or protect SEO value; remove obsolete ones.');
        }

        return $issues;
    }

    private function analyzeSeoFields(string $type, string $identifier, string $name, string $urlKey, string $metaTitle, string $metaDescription, string $metaKeywords): array
    {
        $issues = [];
        $metaTitleLength = mb_strlen(trim(strip_tags($metaTitle)));
        $metaDescriptionLength = mb_strlen(trim(strip_tags($metaDescription)));

        if (trim($metaTitle) === '') {
            $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'missing_meta_title', 'Meta title is missing.', 'Generate a concise unique meta title using the main name and important context.');
        } elseif ($metaTitleLength > 65) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'long_meta_title', 'Meta title is longer than 65 characters.', 'Shorten it while keeping the main keyword and brand/category context.');
        } elseif ($metaTitleLength < 20) {
            $issues[] = $this->issue(self::SEVERITY_NOTICE, 'short_meta_title', 'Meta title is very short.', 'Consider making it more descriptive and unique.');
        }

        if (trim($metaDescription) === '') {
            $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'missing_meta_description', 'Meta description is missing.', 'Generate a useful search snippet with the main benefit and entity context.');
        } elseif ($metaDescriptionLength > 170) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'long_meta_description', 'Meta description is longer than 170 characters.', 'Shorten it to a clean search snippet.');
        } elseif ($metaDescriptionLength < 70) {
            $issues[] = $this->issue(self::SEVERITY_NOTICE, 'short_meta_description', 'Meta description is short.', 'Consider expanding it with relevant product/category value.');
        }

        if (trim($urlKey) === '') {
            $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'missing_url_key', 'URL key is missing.', 'Generate a lowercase hyphenated URL key from the name.');
        } elseif ($urlKey !== strtolower($urlKey) || preg_match('/\s|%20|_|\//', $urlKey)) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'bad_url_key_format', 'URL key format is suspicious.', 'Use lowercase words separated by hyphens, without spaces, underscores, or slashes.');
        }

        return $issues;
    }

    private function appendDuplicateIssues(array &$items, array $values, string $code, string $message): void
    {
        $map = [];
        foreach ($values as $row) {
            $value = mb_strtolower(trim(strip_tags((string) $row['value'])));
            if ($value === '') {
                continue;
            }
            $map[$value][] = (string) $row['identifier'];
        }

        foreach ($map as $identifiers) {
            if (count($identifiers) < 2) {
                continue;
            }
            foreach ($items as &$item) {
                if (in_array($item['identifier'], $identifiers, true)) {
                    $item['issues'][] = $this->issue(self::SEVERITY_WARNING, $code, $message, 'Make this value unique for the entity and store view.');
                }
            }
            unset($item);
        }
    }

    private function summarize(string $scope, int $storeId, int $limit, int $offset, array $filters, array $sections): array
    {
        $summary = [
            'scope' => $scope,
            'store_id' => $storeId,
            'limit' => $limit,
            'offset' => $offset,
            'next_offset' => $offset + $limit,
            'filters' => $filters,
            'has_next_batch' => false,
            'status' => self::STATUS_NO_MATCHES,
            'health_score' => null,
            'ai_fixable_items' => 0,
            'total_entities' => 0,
            'total_available' => 0,
            'total_issues' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'notice_count' => 0,
            'top_issues' => [],
        ];
        $issueCodes = [];

        foreach ($sections as $section) {
            $summary['total_entities'] += (int) ($section['total_entities'] ?? 0);
            $summary['total_available'] += (int) ($section['total_available'] ?? 0);
            $summary['total_issues'] += (int) ($section['total_issues'] ?? 0);
            $summary['critical_count'] += (int) ($section['critical_count'] ?? 0);
            $summary['warning_count'] += (int) ($section['warning_count'] ?? 0);
            $summary['notice_count'] += (int) ($section['notice_count'] ?? 0);
            $summary['ai_fixable_items'] += (int) ($section['ai_fixable_items'] ?? 0);
            if (($section['has_next_batch'] ?? false) === true) {
                $summary['has_next_batch'] = true;
            }
            foreach (($section['issue_codes'] ?? []) as $code => $count) {
                $issueCodes[$code] = ($issueCodes[$code] ?? 0) + (int) $count;
            }
        }

        arsort($issueCodes);
        $summary['top_issues'] = array_slice($issueCodes, 0, 5, true);
        $summary['health_score'] = $this->calculateScore(
            $summary['total_entities'],
            $summary['critical_count'],
            $summary['warning_count'],
            $summary['notice_count']
        );
        $summary['status'] = $this->getStatus((int) $summary['total_entities'], (int) $summary['total_issues']);

        return ['summary' => $summary, 'sections' => $sections];
    }

    private function section(string $code, string $label, array $items, int $totalAvailable, int $offset, array $filters, int $storeId): array
    {
        $section = [
            'code' => $code,
            'label' => $label,
            'status' => self::STATUS_NO_MATCHES,
            'empty_message' => '',
            'empty_detail' => '',
            'total_entities' => count($items),
            'total_available' => $totalAvailable,
            'has_next_batch' => count($items) > 0 && ($offset + count($items)) < $totalAvailable,
            'health_score' => null,
            'ai_fixable_items' => 0,
            'total_issues' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'notice_count' => 0,
            'issue_codes' => [],
            'items' => $items,
        ];

        foreach ($section['items'] as &$item) {
            $item['health_score'] = $this->calculateItemScore($item['issues'] ?? []);
            $item['ai_fixable'] = $this->hasAiFixableIssue($item['issues'] ?? []);
            $item['priority'] = $this->getItemPriority($item['issues'] ?? []);
            $item['next_action'] = $this->getItemNextAction($item['issues'] ?? []);
            if ($item['ai_fixable']) {
                $section['ai_fixable_items']++;
            }
            foreach (($item['issues'] ?? []) as $issue) {
                $section['total_issues']++;
                $key = (string) ($issue['severity'] ?? self::SEVERITY_NOTICE) . '_count';
                if (isset($section[$key])) {
                    $section[$key]++;
                }
                $code = (string) ($issue['code'] ?? 'issue');
                $section['issue_codes'][$code] = ($section['issue_codes'][$code] ?? 0) + 1;
            }
            $item['issues'] = $this->sortIssues($item['issues'] ?? []);
        }
        unset($item);
        arsort($section['issue_codes']);
        $section['health_score'] = $this->calculateScore(
            $section['total_entities'],
            $section['critical_count'],
            $section['warning_count'],
            $section['notice_count']
        );
        $section['status'] = $this->getStatus((int) $section['total_entities'], (int) $section['total_issues']);
        if ($section['status'] === self::STATUS_NO_MATCHES) {
            $section['empty_message'] = 'No items were scanned.';
            $section['empty_detail'] = $this->getNoMatchDetail($code, $filters, $storeId);
        } elseif ($section['status'] === self::STATUS_NO_ISSUES) {
            $section['empty_message'] = count($items) . ' ' . strtolower($label) . ' checked. No SEO issues found.';
        }

        return $section;
    }

    private function calculateScore(int $entities, int $critical, int $warning, int $notice): ?int
    {
        if ($entities <= 0) {
            return null;
        }

        $penalty = ($critical * 18) + ($warning * 8) + ($notice * 3);
        return max(0, min(100, 100 - (int) round($penalty / $entities)));
    }

    private function calculateItemScore(array $issues): int
    {
        $critical = 0;
        $warning = 0;
        $notice = 0;

        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? self::SEVERITY_NOTICE);
            if ($severity === self::SEVERITY_CRITICAL) {
                $critical++;
            } elseif ($severity === self::SEVERITY_WARNING) {
                $warning++;
            } else {
                $notice++;
            }
        }

        return max(0, min(100, 100 - ($critical * 28) - ($warning * 13) - ($notice * 5)));
    }

    private function hasAiFixableIssue(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (!empty($issue['ai_fixable'])) {
                return true;
            }
        }

        return false;
    }

    private function getItemPriority(array $issues): string
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_CRITICAL) {
                return 'fix_now';
            }
        }
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_WARNING) {
                return 'review';
            }
        }

        return $issues ? 'monitor' : 'ok';
    }

    private function getItemNextAction(array $issues): string
    {
        if (!$issues) {
            return 'No action needed.';
        }
        if ($this->hasAiFixableIssue($issues)) {
            return 'Generate improved SEO content with ContentAI, then review URL rewrite notes.';
        }

        foreach ($issues as $issue) {
            $code = (string) ($issue['code'] ?? '');
            if (in_array($code, ['duplicate_request_path', 'empty_request_path', 'empty_target_path', 'self_target'], true)) {
                return 'Review URL rewrite records before changing generated content.';
            }
            if (in_array($code, ['missing_active_rewrite', 'multiple_active_rewrites', 'inactive_entity_direct_rewrite'], true)) {
                return 'Regenerate, remove, or redirect URL rewrites for this entity.';
            }
        }

        return 'Review and decide whether the recommendation is still relevant.';
    }

    private function getPageFromOffset(int $limit, int $offset): int
    {
        return (int) floor($offset / max(1, $limit)) + 1;
    }

    private function item(string $type, string $identifier, string $label, array $issues, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'identifier' => $identifier,
            'label' => $label ?: $identifier,
            'issues' => $issues,
        ], $extra);
    }

    private function issue(string $severity, string $code, string $message, string $recommendation): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'label' => self::ISSUE_LABELS[$code] ?? ucwords(str_replace('_', ' ', $code)),
            'category' => self::ISSUE_CATEGORIES[$code] ?? self::CATEGORY_OPTIONAL,
            'message' => $message,
            'recommendation' => $recommendation,
            'ai_fixable' => isset(self::AI_FIXABLE_CODES[$code]),
        ];
    }

    private function getStatus(int $entities, int $issues): string
    {
        if ($entities <= 0) {
            return self::STATUS_NO_MATCHES;
        }

        return $issues > 0 ? self::STATUS_OK : self::STATUS_NO_ISSUES;
    }

    private function sortIssues(array $issues): array
    {
        $severityOrder = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_NOTICE => 2];
        $categoryOrder = [self::CATEGORY_CONTENT => 0, self::CATEGORY_URL => 1, self::CATEGORY_STATUS => 2, self::CATEGORY_OPTIONAL => 3];
        usort($issues, static function (array $a, array $b) use ($severityOrder, $categoryOrder): int {
            $severityCompare = ($severityOrder[$a['severity'] ?? self::SEVERITY_NOTICE] ?? 9)
                <=> ($severityOrder[$b['severity'] ?? self::SEVERITY_NOTICE] ?? 9);
            if ($severityCompare !== 0) {
                return $severityCompare;
            }

            return ($categoryOrder[$a['category'] ?? self::CATEGORY_OPTIONAL] ?? 9)
                <=> ($categoryOrder[$b['category'] ?? self::CATEGORY_OPTIONAL] ?? 9);
        });

        return $issues;
    }

    private function getNoMatchDetail(string $sectionCode, array $filters, int $storeId): string
    {
        if ($sectionCode === 'cms' && !empty($filters['cms_page_ids'])) {
            $connection = $this->resourceConnection->getConnection();
            $pageTable = $this->resourceConnection->getTableName('cms_page');
            $storeTable = $this->resourceConnection->getTableName('cms_page_store');
            $existingIds = $connection->fetchCol(
                $connection->select()
                    ->from($pageTable, ['page_id'])
                    ->where('page_id IN (?)', $filters['cms_page_ids'])
            );
            if ($existingIds) {
                $assignedIds = $connection->fetchCol(
                    $connection->select()
                        ->from($storeTable, ['page_id'])
                        ->where('page_id IN (?)', $existingIds)
                        ->where('store_id IN (?)', [0, $storeId])
                );
                if (!array_intersect(array_map('intval', $existingIds), array_map('intval', $assignedIds))) {
                    return 'The selected CMS page exists, but it is not assigned to this Store View.';
                }
            }
        }

        return $this->getSectionNoMatchDefault($sectionCode);
    }

    private function getSectionNoMatchDefault(string $sectionCode): string
    {
        return [
            'products' => 'No products matched the selected Store View and filters.',
            'categories' => 'No categories matched the selected Store View and filters.',
            'cms' => 'No CMS pages matched the selected Store View and filters.',
        ][$sectionCode] ?? 'No items matched the selected Store View and filters.';
    }
}
