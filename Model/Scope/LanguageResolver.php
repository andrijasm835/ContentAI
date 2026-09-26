<?php

namespace Nistruct\ContentAI\Model\Scope;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class LanguageResolver
{
    public const XML_DEFAULT_LOCALE = 'contentai/general/default_content_locale';
    public const XML_LANGUAGE_INSTRUCTION = 'contentai/general/language_instruction';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function getLocale(int $storeId): string
    {
        if ($storeId === 0) {
            return (string)($this->scopeConfig->getValue(self::XML_DEFAULT_LOCALE) ?: 'en_US');
        }
        return (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLanguage(string $locale): string
    {
        $normalized = str_replace('-', '_', trim($locale));
        $code = strtolower((string)strtok($normalized, '_'));
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($normalized, 'en');
            if (is_string($name) && trim($name) !== '') {
                return ucfirst($name);
            }
        }
        return $code !== '' ? strtoupper($code) : 'English';
    }

    public function getInstruction(int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_LANGUAGE_INSTRUCTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getScript(string $locale): string
    {
        if (!class_exists(\Locale::class)) {
            return '';
        }
        $parts = \Locale::parseLocale(str_replace('-', '_', $locale));
        $code = (string)($parts['script'] ?? '');
        if ($code === '') {
            return '';
        }
        $name = \Locale::getDisplayScript(str_replace('-', '_', $locale), 'en');
        return is_string($name) ? $name : $code;
    }
}
