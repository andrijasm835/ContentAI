<?php

namespace Nistruct\ContentAI\Model\Provider;

interface AiProviderInterface
{
    public function getCode(): string;
    public function generate(string $prompt, string $imageUrl = ''): AiResponse;
}
