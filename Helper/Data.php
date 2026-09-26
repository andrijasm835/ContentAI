<?php
namespace Nistruct\ContentAI\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filter\Input\MaliciousCode;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Model\Scope\LanguageResolver;

class Data extends AbstractHelper
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI = 'openai';

    public const XML_PATH_IS_ENABLED = 'contentai/general/enabled';
    public const XML_PATH_DEBUG_LOG = 'contentai/general/debug_log';
    public const XML_PATH_PROVIDER = 'contentai/api/provider';
    public const XML_PATH_ANTHROPIC_API_KEY = 'contentai/api/anthropic_api_key';
    public const XML_PATH_ANTHROPIC_MODEL = 'contentai/api/anthropic_model';
    public const XML_PATH_ANTHROPIC_MAX_TOKENS = 'contentai/api/anthropic_max_tokens';
    public const XML_PATH_OPENAI_API_KEY = 'contentai/api/openai_api_key';
    public const XML_PATH_OPENAI_MODEL = 'contentai/api/openai_model';
    public const XML_PATH_OPENAI_MAX_TOKENS = 'contentai/api/openai_max_tokens';

    private $storeManager;
    private $maliciousCode;
    private $encryptor;
    private LanguageResolver $languageResolver;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        MaliciousCode $maliciousCode,
        EncryptorInterface $encryptor,
        LanguageResolver $languageResolver
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
        $this->maliciousCode = $maliciousCode;
        $this->encryptor = $encryptor;
        $this->languageResolver = $languageResolver;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IS_ENABLED);
    }

    public function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG_LOG);
    }

    private function getValue(string $path, $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiProvider(): string
    {
        $provider = $this->getValue(self::XML_PATH_PROVIDER);
        return in_array($provider, [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC], true)
            ? $provider
            : self::PROVIDER_OPENAI;
    }

    public function getAnthropicApiKey(): string
    {
        return $this->decryptConfigValue($this->getValue(self::XML_PATH_ANTHROPIC_API_KEY));
    }

    public function getAnthropicModel(): string
    {
        return $this->getValue(self::XML_PATH_ANTHROPIC_MODEL) ?: 'claude-sonnet-5';
    }

    public function getAnthropicMaxTokens(): int
    {
        return max(100, (int) ($this->getValue(self::XML_PATH_ANTHROPIC_MAX_TOKENS) ?: 1200));
    }

    public function getOpenAiApiKey(): string
    {
        return $this->decryptConfigValue($this->getValue(self::XML_PATH_OPENAI_API_KEY));
    }

    public function getOpenAiModel(): string
    {
        return $this->getValue(self::XML_PATH_OPENAI_MODEL) ?: 'gpt-5.6-luna';
    }

    public function getOpenAiMaxTokens(): int
    {
        return max(100, (int) ($this->getValue(self::XML_PATH_OPENAI_MAX_TOKENS) ?: 1200));
    }

    public function getLocaleByStoreId(int $storeId): string
    {
        return $this->languageResolver->getLocale($storeId);
    }

    public function getLanguageByStoreId(int $storeId): string
    {
        return $this->languageResolver->getLanguage($this->getLocaleByStoreId($storeId));
    }

    public function getLanguageByLocale(string $locale): string
    {
        return $this->languageResolver->getLanguage($locale);
    }

    public function sanitizeHtml(string $value): string
    {
        return (string) $this->maliciousCode->filter($value);
    }

    private function decryptConfigValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return trim((string) $this->encryptor->decrypt($value));
        } catch (\Exception $e) {
            return $value;
        }
    }
}
