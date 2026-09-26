<?php

namespace Nistruct\ContentAI\Model\Scope;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class GenerationContextResolver
{
    private StoreManagerInterface $storeManager;
    private LanguageResolver $languageResolver;

    public function __construct(StoreManagerInterface $storeManager, LanguageResolver $languageResolver)
    {
        $this->storeManager = $storeManager;
        $this->languageResolver = $languageResolver;
    }

    public function resolve(int $targetStoreId, array $websiteIds = [], ?int $sourceStoreId = null): GenerationContext
    {
        $websiteIds = array_values(array_filter(array_unique(array_map('intval', $websiteIds))));
        $websiteId = null;
        $groupId = null;

        if ($targetStoreId > 0) {
            $store = $this->storeManager->getStore($targetStoreId);
            $websiteId = (int)$store->getWebsiteId();
            $groupId = (int)$store->getStoreGroupId();
            if ($websiteIds && !in_array($websiteId, $websiteIds, true)) {
                throw new LocalizedException(__('The selected store view does not belong to a selected website.'));
            }
            $websiteIds = [$websiteId];
            $sourceStoreId = $targetStoreId;
        } else {
            if (!$websiteIds && $sourceStoreId) {
                $websiteIds = [(int)$this->storeManager->getStore($sourceStoreId)->getWebsiteId()];
            }
            if (!$sourceStoreId) {
                $sourceStoreId = $this->getFirstDefaultStoreId($websiteIds);
            }
            if ($sourceStoreId) {
                $source = $this->storeManager->getStore($sourceStoreId);
                $websiteId = (int)$source->getWebsiteId();
                $groupId = (int)$source->getStoreGroupId();
            }
        }

        $locale = $this->languageResolver->getLocale($targetStoreId);
        return new GenerationContext(
            $targetStoreId,
            (int)$sourceStoreId,
            $websiteId,
            $websiteIds,
            $groupId,
            $locale,
            $this->languageResolver->getLanguage($locale),
            $this->languageResolver->getScript($locale),
            $this->languageResolver->getInstruction($targetStoreId)
        );
    }

    private function getFirstDefaultStoreId(array $websiteIds): int
    {
        foreach ($websiteIds as $websiteId) {
            $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
            if ($store) {
                return (int)$store->getId();
            }
        }
        return 0;
    }
}
