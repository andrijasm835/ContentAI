<?php
namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AnthropicModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-sonnet-5', 'label' => __('claude-sonnet-5')],
            ['value' => 'claude-opus-5', 'label' => __('claude-opus-5')],
            ['value' => 'claude-3-5-sonnet-latest', 'label' => __('claude-3-5-sonnet-latest')]
        ];
    }
}
