<?php
namespace Nistruct\ContentAI\Block\Adminhtml\Seo;

use Magento\Backend\Block\Template;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class Form extends Template
{
    private StoreManagerInterface $storeManager;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private PageCollectionFactory $pageCollectionFactory;
    private ResourceConnection $resourceConnection;

    public function __construct(
        Template\Context $context,
        StoreManagerInterface $storeManager,
        CategoryCollectionFactory $categoryCollectionFactory,
        PageCollectionFactory $pageCollectionFactory,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->pageCollectionFactory = $pageCollectionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    public function getPostUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seo/run');
    }

    public function getReportUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seoreport/index');
    }

    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            foreach ($website->getStores() as $store) {
                $options[] = [
                    'value' => (int)$store->getId(),
                    'label' => (string)$store->getName(),
                    'website' => (string)$website->getName(),
                ];
            }
        }

        return $options;
    }

    public function hasCurrentStore(): bool
    {
        return $this->getRequest()->getParam('store_id') !== null;
    }

    public function hasCurrentScope(): bool
    {
        return $this->getRequest()->getParam('scope') !== null;
    }

    public function getScopeOptions(): array
    {
        return [
            'all' => 'Products, Categories, CMS Pages',
            'products' => 'Products',
            'categories' => 'Categories',
            'cms' => 'CMS Pages',
        ];
    }

    public function getCurrentScope(): string
    {
        $scope = (string) $this->getRequest()->getParam('scope', 'all');
        return isset($this->getScopeOptions()[$scope]) ? $scope : 'all';
    }

    public function getCurrentStoreId(): int
    {
        return max(0, (int) $this->getRequest()->getParam('store_id', 0));
    }

    public function getCurrentLimit(): int
    {
        return max(1, min(500, (int) $this->getRequest()->getParam('limit', 100)));
    }

    public function getCurrentOffset(): int
    {
        return max(0, (int) $this->getRequest()->getParam('offset', 0));
    }

    public function getCategoryOptions(bool $includeAny = true): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'is_active', 'level', 'path'])
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->setOrder('path', 'ASC');

        $directProductCounts = $this->getDirectProductCounts();
        $options = $includeAny ? [['value' => 0, 'label' => __('All Categories')]] : [];
        $categories = [];

        foreach ($collection as $category) {
            $categories[(int) $category->getId()] = [
                'id' => (int) $category->getId(),
                'name' => (string) $category->getName(),
                'level' => (int) $category->getLevel(),
                'path' => (string) $category->getPath(),
                'is_active' => (bool) $category->getData('is_active'),
            ];
        }

        foreach ($categories as $category) {
            $productCount = $this->getBranchProductCount($category['path'], $categories, $directProductCounts);
            $label = $this->buildCategoryLabel($category['name'], $category['level'], $productCount);
            if (!$category['is_active']) {
                $label .= ' [' . __('Inactive') . ']';
            }
            $options[] = ['value' => $category['id'], 'label' => $label];
        }

        return $options;
    }

    public function getCmsPageOptions(): array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->setOrder('title', 'ASC');

        $options = [['value' => 0, 'label' => __('All CMS Pages')]];
        foreach ($collection as $page) {
            $label = (string) $page->getTitle() . ' (' . (string) $page->getIdentifier() . ')';
            if (!$page->getIsActive()) {
                $label .= ' [' . __('Inactive') . ']';
            }
            $options[] = ['value' => (int) $page->getId(), 'label' => $label];
        }

        return $options;
    }

    public function getProductMissingFieldOptions(): array
    {
        return [
            'meta_title' => 'SEO Page Title',
            'meta_description' => 'SEO Search Description',
            'meta_keyword' => 'SEO Keywords',
            'url_key' => 'URL Slug',
        ];
    }

    public function getCategoryMissingFieldOptions(): array
    {
        return [
            'meta_title' => 'SEO Page Title',
            'meta_description' => 'SEO Search Description',
            'meta_keywords' => 'SEO Keywords',
            'url_key' => 'URL Slug',
        ];
    }

    public function getCmsMissingFieldOptions(): array
    {
        return [
            'meta_title' => 'SEO Page Title',
            'meta_description' => 'SEO Search Description',
            'meta_keywords' => 'SEO Keywords',
            'identifier' => 'URL Slug / Page Identifier',
        ];
    }

    public function getCurrentArrayParam(string $name): array
    {
        $value = $this->getRequest()->getParam($name, []);
        if (!is_array($value)) {
            $value = preg_split('/[\s,]+/', (string) $value) ?: [];
        }

        return array_values(array_filter(array_map('strval', $value), static function ($item) {
            return trim($item) !== '' && trim($item) !== '0';
        }));
    }

    public function getCurrentParam(string $name, string $default = ''): string
    {
        return (string) $this->getRequest()->getParam($name, $default);
    }

    private function getDirectProductCounts(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_product');
        $select = $connection->select()
            ->from($table, ['category_id', 'product_count' => new \Zend_Db_Expr('COUNT(DISTINCT product_id)')])
            ->group('category_id');

        return array_map('intval', $connection->fetchPairs($select));
    }

    private function getBranchProductCount(string $path, array $categories, array $directProductCounts): int
    {
        $count = 0;
        $prefix = $path . '/';

        foreach ($categories as $category) {
            if ($category['path'] === $path || strpos($category['path'], $prefix) === 0) {
                $count += (int) ($directProductCounts[$category['id']] ?? 0);
            }
        }

        return $count;
    }

    private function buildCategoryLabel(string $name, int $level, int $productCount): string
    {
        $depth = max(0, $level - 2);
        $prefix = $depth ? str_repeat('    ', $depth) . '- ' : '';

        return $prefix . $name . ' (' . $productCount . ')';
    }
}
