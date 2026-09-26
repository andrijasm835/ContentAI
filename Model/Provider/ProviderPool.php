<?php

namespace Nistruct\ContentAI\Model\Provider;

use Nistruct\ContentAI\Model\Query\QueryException;

class ProviderPool
{
    private array $providers;
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }
    public function get(string $code): AiProviderInterface
    {
        $provider = $this->providers[$code] ?? null;
        if (!$provider instanceof AiProviderInterface) {
            throw QueryException::configuration('The configured AI provider is not available.');
        }
        return $provider;
    }
}
