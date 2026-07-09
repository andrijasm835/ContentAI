<?php
namespace Nistruct\ContentAI\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filter\Input\MaliciousCode;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

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
    public const XML_PATH_ANTHROPIC_INPUT_PRICE = 'contentai/api/anthropic_input_price';
    public const XML_PATH_ANTHROPIC_OUTPUT_PRICE = 'contentai/api/anthropic_output_price';
    public const XML_PATH_OPENAI_API_KEY = 'contentai/api/openai_api_key';
    public const XML_PATH_OPENAI_MODEL = 'contentai/api/openai_model';
    public const XML_PATH_OPENAI_MAX_TOKENS = 'contentai/api/openai_max_tokens';
    public const XML_PATH_OPENAI_INPUT_PRICE = 'contentai/api/openai_input_price';
    public const XML_PATH_OPENAI_OUTPUT_PRICE = 'contentai/api/openai_output_price';

    private $storeManager;
    private $maliciousCode;
    private $encryptor;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        MaliciousCode $maliciousCode,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
        $this->maliciousCode = $maliciousCode;
        $this->encryptor = $encryptor;
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
        return $this->getValue(self::XML_PATH_OPENAI_MODEL) ?: 'gpt-5.4-mini';
    }

    public function getOpenAiMaxTokens(): int
    {
        return max(100, (int) ($this->getValue(self::XML_PATH_OPENAI_MAX_TOKENS) ?: 1200));
    }

    public function estimateTokenCost(string $provider, int $inputTokens, int $outputTokens): float
    {
        $inputPrice = $this->getTokenPricePerMillion($provider, 'input');
        $outputPrice = $this->getTokenPricePerMillion($provider, 'output');

        if ($inputPrice <= 0 && $outputPrice <= 0) {
            return 0.0;
        }

        return (($inputTokens / 1000000) * $inputPrice) + (($outputTokens / 1000000) * $outputPrice);
    }

    public function getTokenPricePerMillion(string $provider, string $type): float
    {
        $path = null;
        if ($provider === self::PROVIDER_ANTHROPIC) {
            $path = $type === 'output' ? self::XML_PATH_ANTHROPIC_OUTPUT_PRICE : self::XML_PATH_ANTHROPIC_INPUT_PRICE;
        } else {
            $path = $type === 'output' ? self::XML_PATH_OPENAI_OUTPUT_PRICE : self::XML_PATH_OPENAI_INPUT_PRICE;
        }

        return max(0.0, (float) $this->getValue($path));
    }

    public function getLocaleByStoreId(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLanguageByStoreId(int $storeId): string
    {
        return $this->getLanguageByLocale($this->getLocaleByStoreId($storeId));
    }

    public function getLanguageByLocale(string $locale): string
    {
        switch ($locale) {
            case 'sr_RS':
            case 'sr_Latn_RS':
                return 'Serbian Latin';
            case 'hr_HR':
                return 'Croatian Latin';
            case 'bs_BA':
                return 'Bosnian Latin';
            case 'en_US':
            case 'en_GB':
            default:
                return 'English';
        }
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
