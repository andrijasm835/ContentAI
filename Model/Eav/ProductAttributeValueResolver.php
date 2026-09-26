<?php

namespace Nistruct\ContentAI\Model\Eav;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Nistruct\ContentAI\Model\Media\ImageLabelService;

class ProductAttributeValueResolver
{
    private ProductRepositoryInterface $productRepository;
    private Config $eavConfig;
    private ResourceConnection $resource;
    private MetadataPool $metadataPool;
    private AttributeStorageScopeResolver $scopeResolver;
    private ImageLabelService $imageLabels;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Config $eavConfig,
        ResourceConnection $resource,
        MetadataPool $metadataPool,
        AttributeStorageScopeResolver $scopeResolver,
        ImageLabelService $imageLabels
    ) {
        $this->productRepository = $productRepository;
        $this->eavConfig = $eavConfig;
        $this->resource = $resource;
        $this->metadataPool = $metadataPool;
        $this->scopeResolver = $scopeResolver;
        $this->imageLabels = $imageLabels;
    }

    public function resolve(int $productId, string $attributeCode, int $storeId): array
    {
        if ($this->imageLabels->supports($attributeCode)) {
            $own = $this->imageLabels->getOwnValue($productId, $attributeCode, $storeId);
            $default = $this->imageLabels->getDefaultValueForTargetImage($productId, $attributeCode, $storeId);
            return [
                'default_value' => $default,
                'store_value' => $storeId === 0 ? $default : $own,
                'effective_value' => $own ?? $default,
                'is_inherited' => $storeId > 0 && $own === null,
            ];
        }
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);
        if (!$attribute || !(int)$attribute->getAttributeId()) {
            return ['default_value' => null, 'store_value' => null, 'effective_value' => null, 'is_inherited' => false];
        }
        $storageStoreId = $this->scopeResolver->resolve($attribute, $storeId);
        $defaultProduct = $this->productRepository->getById($productId, false, 0, true);
        $effectiveProduct = $storeId === 0
            ? $defaultProduct
            : $this->productRepository->getById($productId, false, $storeId, true);
        $defaultValue = $defaultProduct->getData($attributeCode);
        $storeValue = $storageStoreId === 0
            ? $defaultValue
            : $this->getOwnValue($effectiveProduct, $attribute, $storageStoreId);

        return [
            'default_value' => $defaultValue,
            'store_value' => $storeValue,
            'effective_value' => $effectiveProduct->getData($attributeCode),
            'is_inherited' => $storageStoreId > 0 && $storeValue === null,
        ];
    }

    public function isMissingOwnValue(int $productId, string $attributeCode, int $storeId): bool
    {
        $values = $this->resolve($productId, $attributeCode, $storeId);
        $value = $storeId === 0 ? $values['default_value'] : $values['store_value'];
        return $value === null || trim(strip_tags((string)$value)) === '' || trim((string)$value) === '-';
    }

    private function getOwnValue(ProductInterface $product, $attribute, int $storeId)
    {
        $backendType = (string)$attribute->getBackendType();
        if ($backendType === '' || $backendType === 'static') {
            return $product->getData((string)$attribute->getAttributeCode());
        }
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkField = $metadata->getLinkField();
        $linkValue = $product->getData($linkField) ?: $product->getId();
        $select = $this->resource->getConnection()->select()
            ->from($attribute->getBackendTable(), ['value'])
            ->where($linkField . ' = ?', $linkValue)
            ->where('attribute_id = ?', (int)$attribute->getAttributeId())
            ->where('store_id = ?', $storeId)
            ->limit(1);
        $value = $this->resource->getConnection()->fetchOne($select);
        return $value === false ? null : $value;
    }
}
