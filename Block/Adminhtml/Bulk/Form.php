<?php

namespace Nistruct\ContentAI\Block\Adminhtml\Bulk;

use Magento\Backend\Block\Template;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\System\Store as SystemStore;

class Form extends Template
{
    private SystemStore $systemStore;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private ResourceConnection $resourceConnection;

    public function __construct(
        Template\Context $context,
        SystemStore $systemStore,
        CategoryCollectionFactory $categoryCollectionFactory,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->systemStore = $systemStore;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    public function getPostUrl(): string
    {
        return $this->getUrl('nistruct_contentai/bulk/generate');
    }

    public function getStoreOptions(): array
    {
        $options = [['value' => 0, 'label' => __('All Store Views')]];

        foreach ($this->systemStore->getStoreValuesForForm(false, true) as $option) {
            if (is_array($option['value'] ?? null)) {
                foreach ($option['value'] as $storeOption) {
                    $options[] = $storeOption;
                }
                continue;
            }

            $options[] = $option;
        }

        return $options;
    }

    public function getFieldOptions(): array
    {
        return [
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
    }

    public function getCategoryOptions(): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'is_active', 'level', 'path'])
            ->addAttributeToFilter('is_active', 1)
            ->addIsActiveFilter()
            ->setOrder('path', 'ASC');

        $directProductCounts = $this->getDirectProductCounts();
        $options = [['value' => 0, 'label' => __('Any Category')]];
        $categories = [];

        foreach ($collection as $category) {
            $level = (int)$category->getLevel();
            if ($level < 2) {
                continue;
            }

            $categories[(int)$category->getId()] = [
                'id' => (int)$category->getId(),
                'name' => (string)$category->getName(),
                'level' => $level,
                'path' => (string)$category->getPath(),
            ];
        }

        foreach ($categories as $category) {
            $productCount = $this->getBranchProductCount($category['path'], $categories, $directProductCounts);
            if ($productCount <= 0) {
                continue;
            }

            $options[] = [
                'value' => $category['id'],
                'label' => $this->buildCategoryLabel($category['name'], $category['level'], $productCount),
            ];
        }

        return $options;
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
                $count += (int)($directProductCounts[$category['id']] ?? 0);
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
