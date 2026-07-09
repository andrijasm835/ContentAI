<?php
namespace Nistruct\ContentAI\Model\Seo;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;

class Analyzer
{
    private const SEVERITY_CRITICAL = 'critical';
    private const SEVERITY_WARNING = 'warning';
    private const SEVERITY_NOTICE = 'notice';

    private ProductCollectionFactory $productCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private PageCollectionFactory $pageCollectionFactory;
    private ResourceConnection $resourceConnection;

    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        PageCollectionFactory $pageCollectionFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    public function analyze(string $scope, int $storeId, int $limit, int $offset = 0): array
    {
        $scope = in_array($scope, ['all', 'products', 'categories', 'cms'], true) ? $scope : 'all';
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $offset = (int) (floor($offset / $limit) * $limit);
        $sections = [];
        $duplicatePaths = $this->getDuplicateRequestPaths($storeId);

        if ($scope === 'all' || $scope === 'products') {
            $sections['products'] = $this->analyzeProducts($storeId, $limit, $offset, $duplicatePaths);
        }
        if ($scope === 'all' || $scope === 'categories') {
            $sections['categories'] = $this->analyzeCategories($storeId, $limit, $offset, $duplicatePaths);
        }
        if ($scope === 'all' || $scope === 'cms') {
            $sections['cms'] = $this->analyzeCmsPages($storeId, $limit, $offset, $duplicatePaths);
        }

        return $this->summarize($scope, $storeId, $limit, $offset, $sections);
    }

    private function analyzeProducts(int $storeId, int $limit, int $offset, array $duplicatePaths): array
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
        return $this->section('Products', $items, $collection->getSize(), $offset);
    }

    private function analyzeCategories(int $storeId, int $limit, int $offset, array $duplicatePaths): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active', 'url_key', 'meta_title', 'meta_keywords', 'meta_description'])
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($limit)
            ->setCurPage($this->getPageFromOffset($limit, $offset));

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
        return $this->section('Categories', $items, $collection->getSize(), $offset);
    }

    private function analyzeCmsPages(int $storeId, int $limit, int $offset, array $duplicatePaths): array
    {
        $collection = $this->pageCollectionFactory->create();
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
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
        return $this->section('CMS Pages', $items, $collection->getSize(), $offset);
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

        if (trim($metaKeywords) === '') {
            $issues[] = $this->issue(self::SEVERITY_NOTICE, 'missing_meta_keywords', 'Meta keywords are missing.', 'Optional: add a short comma-separated keyword set if this project still uses meta keywords.');
        }

        if (trim($urlKey) === '') {
            $issues[] = $this->issue(self::SEVERITY_CRITICAL, 'missing_url_key', 'URL key is missing.', 'Generate a lowercase hyphenated URL key from the name.');
        } elseif ($urlKey !== strtolower($urlKey) || preg_match('/\s|%20|_|\//', $urlKey)) {
            $issues[] = $this->issue(self::SEVERITY_WARNING, 'bad_url_key_format', 'URL key format is suspicious.', 'Use lowercase words separated by hyphens, without spaces, underscores, or slashes.');
        }

        if ($name !== '' && $urlKey !== '' && stripos(str_replace('-', ' ', $urlKey), mb_substr($name, 0, min(8, mb_strlen($name)))) === false) {
            $issues[] = $this->issue(self::SEVERITY_NOTICE, 'url_key_may_not_match_name', 'URL key may not match the name.', 'Review whether the URL key still describes this ' . str_replace('_', ' ', $type) . '.');
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
        }
    }

    private function summarize(string $scope, int $storeId, int $limit, int $offset, array $sections): array
    {
        $summary = [
            'scope' => $scope,
            'store_id' => $storeId,
            'limit' => $limit,
            'offset' => $offset,
            'next_offset' => $offset + $limit,
            'has_next_batch' => false,
            'total_entities' => 0,
            'total_available' => 0,
            'total_issues' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'notice_count' => 0,
        ];

        foreach ($sections as $section) {
            $summary['total_entities'] += (int) ($section['total_entities'] ?? 0);
            $summary['total_available'] += (int) ($section['total_available'] ?? 0);
            $summary['total_issues'] += (int) ($section['total_issues'] ?? 0);
            $summary['critical_count'] += (int) ($section['critical_count'] ?? 0);
            $summary['warning_count'] += (int) ($section['warning_count'] ?? 0);
            $summary['notice_count'] += (int) ($section['notice_count'] ?? 0);
            if (($section['has_next_batch'] ?? false) === true) {
                $summary['has_next_batch'] = true;
            }
        }

        return ['summary' => $summary, 'sections' => $sections];
    }

    private function section(string $label, array $items, int $totalAvailable, int $offset): array
    {
        $section = [
            'label' => $label,
            'total_entities' => count($items),
            'total_available' => $totalAvailable,
            'has_next_batch' => count($items) > 0 && ($offset + count($items)) < $totalAvailable,
            'total_issues' => 0,
            'critical_count' => 0,
            'warning_count' => 0,
            'notice_count' => 0,
            'items' => $items,
        ];

        foreach ($items as $item) {
            foreach (($item['issues'] ?? []) as $issue) {
                $section['total_issues']++;
                $key = (string) ($issue['severity'] ?? self::SEVERITY_NOTICE) . '_count';
                if (isset($section[$key])) {
                    $section[$key]++;
                }
            }
        }

        return $section;
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
            'message' => $message,
            'recommendation' => $recommendation,
        ];
    }
}
