<?php

namespace Nistruct\ContentAI\Model\Eav;

use Magento\Store\Model\StoreManagerInterface;

class AttributeStorageScopeResolver
{
    private StoreManagerInterface $storeManager;
    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    public function resolve($attribute, int $targetStoreId): int
    {
        if ($attribute->isScopeGlobal() || $targetStoreId === 0) {
            return 0;
        }
        if ($attribute->isScopeWebsite()) {
            $store = $this->storeManager->getStore($targetStoreId)->getWebsite()->getDefaultStore();
            return $store ? (int)$store->getId() : 0;
        }
        return $targetStoreId;
    }
}
