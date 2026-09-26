<?php

namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Eav\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;
use Nistruct\ContentAI\Model\Field\AttributeEligibility;

class CategoryAttributes implements OptionSourceInterface
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
        $out = [];
        foreach ($this->config->getEntityType('catalog_category')->getAttributeCollection() as $a) {
            if ($this->eligibility->isEligible('catalog_category', $a)) {
                $out[] = ['value' => $a->getAttributeCode(),'label' => $a->getDefaultFrontendLabel() ?: $a->getAttributeCode()];

            }
        } usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }
}
