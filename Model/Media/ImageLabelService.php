<?php

namespace Nistruct\ContentAI\Model\Media;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

class ImageLabelService
{
    public const FIELDS = [
        'image_label' => 'image',
        'small_image_label' => 'small_image',
        'thumbnail_label' => 'thumbnail',
    ];
    private ProductRepositoryInterface $products;
    private ResourceConnection $resource;
    private MetadataPool $metadataPool;
    public function __construct(
        ProductRepositoryInterface $products,
        ResourceConnection $resource,
        MetadataPool $metadataPool
    ) {
        $this->products = $products;
        $this->resource = $resource;
        $this->metadataPool = $metadataPool;
    }
    public function supports(string $code): bool
    {
        return isset(self::FIELDS[$code]);
    }
    public function getOwnValue(int $productId, string $code, int $storeId)
    {
        $row = $this->getGalleryRow($productId, $code, $storeId);
        return $row ? $row['label'] : null;
    }
    public function getDefaultValueForTargetImage(int $productId, string $code, int $targetStoreId)
    {
        $identity = $this->getGalleryIdentity($productId, $code, $targetStoreId);
        if (!$identity) {
            return null;
        }
        $table = $this->resource->getTableName('catalog_product_entity_media_gallery_value');
        $select = $this->resource->getConnection()->select()
            ->from($table, ['label'])
            ->where('value_id = ?', $identity['value_id'])
            ->where('entity_id = ?', $identity['entity_id'])
            ->where('store_id = 0')
            ->limit(1);
        $value = $this->resource->getConnection()->fetchOne($select);
        return $value === false ? null : $value;
    }
    public function apply(int $productId, string $code, string $label, int $storeId): bool
    {
        $base = $this->getGalleryIdentity($productId, $code, $storeId);
        if (!$base) {
            return false;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity_media_gallery_value');
        $default = $this->getGalleryRowByIdentity($base, 0);
        $where = [
            'value_id = ?' => (int)$base['value_id'],
            'store_id = ?' => $storeId,
            'entity_id = ?' => (int)$base['entity_id'],
        ];
        if ($this->getGalleryRowByIdentity($base, $storeId)) {
            $connection->update($table, ['label' => $label], $where);
        } else {
            $connection->insert($table, [
                'value_id' => (int)$base['value_id'],
                'store_id' => $storeId,
                'entity_id' => (int)$base['entity_id'],
                'label' => $label,
                'position' => $default['position'] ?? 0,
                'disabled' => $default['disabled'] ?? 0,
            ]);
        }
        return true;
    }
    private function getGalleryRow(int $productId, string $code, int $storeId): ?array
    {
        $identity = $this->getGalleryIdentity($productId, $code, $storeId);
        return $identity ? $this->getGalleryRowByIdentity($identity, $storeId) : null;
    }
    private function getGalleryIdentity(int $productId, string $code, int $storeId): ?array
    {
        if (!$this->supports($code)) {
            return null;
        }
        $product = $this->products->getById($productId, false, $storeId, true);
        $file = (string)$product->getData(self::FIELDS[$code]);
        if ($file === '' || $file === 'no_selection') {
            return null;
        }
        $connection = $this->resource->getConnection();
        $gallery = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $link = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkValue = $product->getData($metadata->getLinkField()) ?: $productId;
        $select = $connection->select()
            ->from(['g' => $gallery], ['value_id'])
            ->join(
                ['l' => $link],
                'l.value_id=g.value_id',
                ['entity_id' => $metadata->getLinkField()]
            )
            ->where('g.value = ?', $file)
            ->where('l.' . $metadata->getLinkField() . ' = ?', $linkValue)
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }
    private function getGalleryRowByIdentity(array $identity, int $storeId): ?array
    {
        $table = $this->resource->getTableName('catalog_product_entity_media_gallery_value');
        $select = $this->resource->getConnection()->select()
            ->from($table, ['value_id', 'entity_id', 'label', 'position', 'disabled'])
            ->where('value_id = ?', $identity['value_id'])
            ->where('entity_id = ?', $identity['entity_id'])
            ->where('store_id = ?', $storeId)
            ->limit(1);
        $row = $this->resource->getConnection()->fetchRow($select);
        return $row ?: null;
    }
}
