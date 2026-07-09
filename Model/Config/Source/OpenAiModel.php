<?php
namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenAiModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gpt-5.4-mini', 'label' => __('gpt-5.4-mini')],
            ['value' => 'gpt-5.4', 'label' => __('gpt-5.4')],
            ['value' => 'gpt-4.1-mini', 'label' => __('gpt-4.1-mini')]
        ];
    }
}
