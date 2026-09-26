<?php

namespace Nistruct\ContentAI\Model\Field;

use Magento\Eav\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CategoryFieldProvider
{
    private const STANDARD = ['name', 'description', 'meta_title', 'meta_keywords', 'meta_description'];
    private Config $eavConfig;
    private ScopeConfigInterface $scopeConfig;
    private AttributeEligibility $eligibility;
    private ?array $fields = null;

    public function __construct(Config $eavConfig, ScopeConfigInterface $scopeConfig, AttributeEligibility $eligibility)
    {
        $this->eavConfig = $eavConfig;
        $this->scopeConfig = $scopeConfig;
        $this->eligibility = $eligibility;
    }

    public function getFields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }
        $fields = [];
        $allowed = array_filter(explode(
            ',',
            (string)$this->scopeConfig->getValue('contentai/general/category_attributes')
        ));
        foreach ($this->eavConfig->getEntityType('catalog_category')->getAttributeCollection() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            $input = (string)$attribute->getFrontendInput();
            $backend = (string)$attribute->getBackendType();
            if (!$this->eligibility->isEligible('catalog_category', $attribute)) {
                continue;
            }
            if (!(bool)$attribute->getIsUserDefined() && !in_array($code, self::STANDARD, true)) {
                continue;
            }
            if (!in_array($code, $allowed, true)) {
                continue;
            }
            $fields[$code] = [
                'code' => $code,
                'label' => (string)($attribute->getDefaultFrontendLabel() ?: $code),
                'frontend_input' => $input,
                'allows_html' => $input === 'textarea' && (bool)$attribute->getIsHtmlAllowedOnFront(),
                'scope' => method_exists($attribute, 'isScopeGlobal') && $attribute->isScopeGlobal()
                    ? 'global'
                    : 'store',
            ];
        }
        ksort($fields);
        return $this->fields = $fields;
    }

    public function getField(string $code): ?array
    {
        return $this->getFields()[$code] ?? null;
    }
}
