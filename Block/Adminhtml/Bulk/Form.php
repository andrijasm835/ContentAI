<?php

namespace Nistruct\ContentAI\Block\Adminhtml\Bulk;

use Magento\Backend\Block\Template;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;

class Form extends Template
{
    private StoreManagerInterface $storeManager;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private ResourceConnection $resourceConnection;
    private EavConfig $eavConfig;
    private ProductFieldProvider $fieldProvider;

    public function __construct(
        Template\Context $context,
        StoreManagerInterface $storeManager,
        CategoryCollectionFactory $categoryCollectionFactory,
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        ProductFieldProvider $fieldProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->fieldProvider = $fieldProvider;
    }

    public function getPostUrl(): string
    {
        return $this->getUrl('nistruct_contentai/bulk/generate');
    }

    public function getWebsiteOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $defaultStore = $website->getDefaultStore();
            $options[] = [
                'value' => (int)$website->getId(),
                'label' => (string)$website->getName(),
                'default_store_id' => $defaultStore ? (int)$defaultStore->getId() : 0,
            ];
        }

        return $options;
    }

    public function getStoreOptions(): array
    {
        $options = [[
            'value' => 0,
            'website_id' => 0,
            'label' => (string)__('All Store Views'),
            'is_global' => true,
        ]];
        foreach ($this->storeManager->getStores() as $store) {
            $options[] = [
                'value' => (int)$store->getId(),
                'website_id' => (int)$store->getWebsiteId(),
                'label' => (string)$store->getName(),
                'is_global' => false,
            ];
        }

        return $options;
    }

    public function getFieldOptions(): array
    {
        return array_column($this->fieldProvider->getFields(), 'label', 'code');
    }

    public function getCategoryOptionGroups(): array
    {
        $groups = [];
        foreach ($this->storeManager->getStores() as $store) {
            $groups[(int)$store->getId()] = $this->getCategoryOptionsForStore(
                (int)$store->getId(),
                (int)$store->getWebsiteId(),
                (int)$store->getRootCategoryId()
            );
        }

        return $groups;
    }

    private function getCategoryOptionsForStore(int $storeId, int $websiteId, int $rootCategoryId): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['name', 'is_active', 'level', 'path'])
            ->addAttributeToFilter('is_active', 1)
            ->addFieldToFilter('path', ['like' => '1/' . $rootCategoryId . '/%'])
            ->setOrder('path', 'ASC');

        $directProductIds = $this->getDirectProductIds($websiteId, $storeId);
        $options = [];
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
            $productCount = $this->getBranchProductCount($category['path'], $categories, $directProductIds);
            if ($productCount <= 0) {
                continue;
            }

            $options[] = [
                'value' => $category['id'],
                'label' => $category['name'],
                'path_label' => $this->buildCategoryPathLabel($category, $categories),
                'depth' => max(0, $category['level'] - 2),
                'product_count' => $productCount,
            ];
        }

        return $options;
    }

    private function getDirectProductIds(int $websiteId, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $categoryProductTable = $this->resourceConnection->getTableName('catalog_category_product');
        $productWebsiteTable = $this->resourceConnection->getTableName('catalog_product_website');
        $productIntTable = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $statusAttributeId = (int)$this->eavConfig->getAttribute('catalog_product', 'status')->getId();
        $select = $connection->select()
            ->from(['category_product' => $categoryProductTable], ['category_id', 'product_id'])
            ->joinInner(
                ['product_website' => $productWebsiteTable],
                'product_website.product_id = category_product.product_id AND product_website.website_id = ' . $websiteId,
                []
            )
            ->joinInner(
                ['default_status' => $productIntTable],
                'default_status.entity_id = category_product.product_id'
                    . ' AND default_status.attribute_id = ' . $statusAttributeId
                    . ' AND default_status.store_id = 0',
                []
            )
            ->joinLeft(
                ['store_status' => $productIntTable],
                'store_status.entity_id = category_product.product_id'
                    . ' AND store_status.attribute_id = ' . $statusAttributeId
                    . ' AND store_status.store_id = ' . $storeId,
                []
            )
            ->where('COALESCE(store_status.value, default_status.value) = ?', 1);

        $productIds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $productIds[(int)$row['category_id']][(int)$row['product_id']] = true;
        }

        return $productIds;
    }

    private function getBranchProductCount(string $path, array $categories, array $directProductIds): int
    {
        $productIds = [];
        $prefix = $path . '/';

        foreach ($categories as $category) {
            if ($category['path'] === $path || strpos($category['path'], $prefix) === 0) {
                $productIds += $directProductIds[$category['id']] ?? [];
            }
        }

        return count($productIds);
    }

    private function buildCategoryPathLabel(array $category, array $categories): string
    {
        $names = [];
        foreach (array_map('intval', explode('/', $category['path'])) as $categoryId) {
            if (isset($categories[$categoryId])) {
                $names[] = $categories[$categoryId]['name'];
            }
        }

        return implode(' / ', $names);
    }
}
