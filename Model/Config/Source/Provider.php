<?php
namespace Nistruct\ContentAI\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Nistruct\ContentAI\Helper\Data;

class Provider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Data::PROVIDER_OPENAI, 'label' => __('OpenAI / GPT')],
            ['value' => Data::PROVIDER_ANTHROPIC, 'label' => __('Anthropic / Claude')]
        ];
    }
}
