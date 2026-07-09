<?php
namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EntityType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'product', 'label' => __('Product')],
            ['value' => 'category', 'label' => __('Category')],
        ];
    }
}
