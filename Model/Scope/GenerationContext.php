<?php

namespace Nistruct\ContentAI\Model\Scope;

class GenerationContext
{
    private int $targetStoreId;
    private int $sourceStoreId;
    private ?int $websiteId;
    private array $websiteIds;
    private ?int $storeGroupId;
    private string $locale;
    private string $language;
    private string $script;
    private string $languageInstruction;

    public function __construct(
        int $targetStoreId,
        int $sourceStoreId,
        ?int $websiteId,
        array $websiteIds,
        ?int $storeGroupId,
        string $locale,
        string $language,
        string $script,
        string $languageInstruction
    ) {
        $this->targetStoreId = $targetStoreId;
        $this->sourceStoreId = $sourceStoreId;
        $this->websiteId = $websiteId;
        $this->websiteIds = array_values(array_unique(array_map('intval', $websiteIds)));
        $this->storeGroupId = $storeGroupId;
        $this->locale = $locale;
        $this->language = $language;
        $this->script = $script;
        $this->languageInstruction = $languageInstruction;
    }

    public function getTargetStoreId(): int
    {
        return $this->targetStoreId;
    }
    public function getSourceStoreId(): int
    {
        return $this->sourceStoreId;
    }
    public function isDefaultScope(): bool
    {
        return $this->targetStoreId === 0;
    }
    public function getWebsiteId(): ?int
    {
        return $this->websiteId;
    }
    public function getWebsiteIds(): array
    {
        return $this->websiteIds;
    }
    public function getStoreGroupId(): ?int
    {
        return $this->storeGroupId;
    }
    public function getLocale(): string
    {
        return $this->locale;
    }
    public function getLanguage(): string
    {
        return $this->language;
    }
    public function getScript(): string
    {
        return $this->script;
    }
    public function getLanguageInstruction(): string
    {
        return $this->languageInstruction;
    }

    public function toArray(): array
    {
        return [
            'store_id' => $this->targetStoreId,
            'source_store_id' => $this->sourceStoreId,
            'is_default_scope' => $this->isDefaultScope(),
            'website_id' => $this->websiteId,
            'website_ids' => $this->websiteIds,
            'store_group_id' => $this->storeGroupId,
            'locale' => $this->locale,
            'language' => $this->language,
            'script' => $this->script,
        ];
    }
}
