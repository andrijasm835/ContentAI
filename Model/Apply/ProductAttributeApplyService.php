<?php

namespace Nistruct\ContentAI\Model\Apply;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Eav\AttributeStorageScopeResolver;
use Nistruct\ContentAI\Model\Media\ImageLabelService;
use Magento\Framework\App\CacheInterface;
use Magento\Catalog\Model\Product;

class ProductAttributeApplyService
{
    private ProductRepositoryInterface $productRepository;
    private ProductAction $productAction;
    private ProductFieldProvider $fieldProvider;
    private StoreManagerInterface $storeManager;
    private HelperData $helper;
    private AttributeStorageScopeResolver $scopeResolver;
    private ImageLabelService $imageLabels;
    private CacheInterface $cache;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductAction $productAction,
        ProductFieldProvider $fieldProvider,
        StoreManagerInterface $storeManager,
        HelperData $helper,
        AttributeStorageScopeResolver $scopeResolver,
        ImageLabelService $imageLabels,
        CacheInterface $cache
    ) {
        $this->productRepository = $productRepository;
        $this->productAction = $productAction;
        $this->fieldProvider = $fieldProvider;
        $this->storeManager = $storeManager;
        $this->helper = $helper;
        $this->scopeResolver = $scopeResolver;
        $this->imageLabels = $imageLabels;
        $this->cache = $cache;
    }

    public function apply(string $sku, array $values, int $targetStoreId): array
    {
        $product = $this->productRepository->get($sku, false, $targetStoreId, true);
        $batches = [];
        $skipped = [];
        $saved = [];
        foreach ($values as $code => $value) {
            $field = $this->fieldProvider->getField((string)$code);
            if (!$field
                || !$this->fieldProvider->isFieldInAttributeSet((string)$code, (int)$product->getAttributeSetId())
                || !is_scalar($value)
            ) {
                continue;
            }
            if ($field['scope'] === 'global' && $targetStoreId > 0) {
                $skipped[$code] = 'This attribute is global and can only be written in All Store Views.';
                continue;
            }
            $clean = $this->helper->sanitizeHtml((string)$value);
            if ($this->imageLabels->supports((string)$code)) {
                if ($this->imageLabels->apply((int)$product->getId(), (string)$code, $clean, $targetStoreId)) {
                    $saved[$code] = $clean;
                }
                continue;
            }
            $attribute = $product->getResource()->getAttribute((string)$code);
            $batches[$this->scopeResolver->resolve($attribute, $targetStoreId)][$code] = $clean;
        }
        foreach ($batches as $storeId => $data) {
            $this->productAction->updateAttributes([(int)$product->getId()], $data, (int)$storeId);
            $saved += $data;
        }
        if (!$saved && $skipped) {
            throw new LocalizedException(__(implode(' ', array_values($skipped))));
        }
        if ($saved) {
            $this->cache->clean([Product::CACHE_TAG . '_' . (int)$product->getId()]);
        }
        return ['saved' => $saved, 'skipped' => $skipped];
    }
}
