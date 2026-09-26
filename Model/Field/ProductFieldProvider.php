<?php

namespace Nistruct\ContentAI\Model\Field;

use Magento\Eav\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ProductFieldProvider
{
    private const STANDARD_FIELDS = [
        'name', 'short_description', 'description', 'meta_title', 'meta_keyword', 'meta_description',
        'image_label', 'small_image_label', 'thumbnail_label',
    ];
    public const PSEUDO_FIELDS = [
        'image_label' => 'Main Image Alt Text',
        'small_image_label' => 'Small Image Alt Text',
        'thumbnail_label' => 'Thumbnail Alt Text',
    ];
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
            (string)$this->scopeConfig->getValue('contentai/general/product_attributes')
        ));
        $collection = $this->eavConfig->getEntityType('catalog_product')->getAttributeCollection();
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            $input = (string)$attribute->getFrontendInput();
            $backend = (string)$attribute->getBackendType();
            if (!$this->eligibility->isEligible('catalog_product', $attribute)) {
                continue;
            }
            if (!(bool)$attribute->getIsUserDefined() && !in_array($code, self::STANDARD_FIELDS, true)) {
                continue;
            }
            if (!in_array($code, $allowed, true)) {
                continue;
            }
            $fields[$code] = [
                'code' => $code,
                'label' => (string)($attribute->getDefaultFrontendLabel() ?: $code),
                'frontend_input' => $input,
                'backend_type' => $backend,
                'allows_html' => $input === 'textarea' && (bool)$attribute->getIsHtmlAllowedOnFront(),
                'scope' => $attribute->isScopeGlobal()
                    ? 'global'
                    : ($attribute->isScopeWebsite() ? 'website' : 'store'),
                'pseudo' => false,
            ];
        }
        foreach (self::PSEUDO_FIELDS as $code => $label) {
            if (!in_array($code, $allowed, true)) {
                continue;
            }
            if (!isset($fields[$code])) {
                $fields[$code] = [
                    'code' => $code,
                    'label' => $label,
                    'frontend_input' => 'text',
                    'backend_type' => 'varchar',
                    'allows_html' => false,
                    'scope' => 'store',
                    'pseudo' => true,
                ];
            }
        }
        ksort($fields);
        return $this->fields = $fields;
    }

    public function getField(string $code): ?array
    {
        return $this->getFields()[$code] ?? null;
    }
    public function isFieldInAttributeSet(string $code, int $attributeSetId): bool
    {
        if (isset(self::PSEUDO_FIELDS[$code])) {
            return true;
        }
        $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
        return $attribute && (bool)$attribute->isInSet($attributeSetId);
    }
    public function getFieldsForAttributeSet(int $attributeSetId): array
    {
        return array_filter(
            $this->getFields(),
            fn (array $field): bool => $this->isFieldInAttributeSet($field['code'], $attributeSetId)
        );
    }
    public function filterCodes(array $codes): array
    {
        $allowed = $this->getFields();
        return array_values(array_unique(array_filter(
            array_map('strval', $codes),
            static fn ($code) => isset($allowed[$code])
        )));
    }
}
