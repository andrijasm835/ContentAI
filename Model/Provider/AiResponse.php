<?php

namespace Nistruct\ContentAI\Model\Provider;

class AiResponse
{
    private string $content;
    private array $usage;
    public function __construct(string $content, array $usage = [])
    {
        $this->content = $content;
        $this->usage = $usage;
    }
    public function getContent(): string
    {
        return $this->content;
    }
    public function getUsage(): array
    {
        return $this->usage;
    }
}
