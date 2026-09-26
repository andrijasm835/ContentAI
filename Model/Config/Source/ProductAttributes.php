<?php

namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Eav\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;
use Nistruct\ContentAI\Model\Field\AttributeEligibility;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;

class ProductAttributes implements OptionSourceInterface
{
    private Config $config;
    private AttributeEligibility $eligibility;

    public function __construct(Config $config, AttributeEligibility $eligibility)
    {
        $this->config = $config;
        $this->eligibility = $eligibility;
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->config->getEntityType('catalog_product')->getAttributeCollection() as $attribute) {
            if (!$this->eligibility->isEligible('catalog_product', $attribute)) {
                continue;
            }
            $code = (string)$attribute->getAttributeCode();
            $options[$code] = [
                'value' => $code,
                'label' => $attribute->getDefaultFrontendLabel() ?: $code,
            ];
        }
        foreach (ProductFieldProvider::PSEUDO_FIELDS as $code => $label) {
            $options[$code] = ['value' => $code, 'label' => $label];
        }
        $options = array_values($options);
        usort($options, static fn (array $a, array $b): int => strcmp((string)$a['label'], (string)$b['label']));
        return $options;
    }
}
