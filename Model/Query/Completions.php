<?php
namespace Nistruct\ContentAI\Model\Query;

use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Provider\ProviderPool;

class Completions
{
    private HelperData $helper;
    private ProviderPool $providerPool;
    private array $usageMetadata = [];

    public function __construct(HelperData $helper, ProviderPool $providerPool)
    {
        $this->helper = $helper;
        $this->providerPool = $providerPool;
    }

    public function generateContent(string $prompt, string $imageUrl = ''): string
    {
        $response = $this->providerPool->get($this->helper->getApiProvider())->generate($prompt, $imageUrl);
        $this->usageMetadata = $this->mergeUsageMetadata($this->usageMetadata, $response->getUsage());
        return $response->getContent();
    }

    public function resetUsageMetadata(): void { $this->usageMetadata = []; }
    public function getUsageMetadata(): array { return $this->usageMetadata; }

    private function mergeUsageMetadata(array $current, array $new): array
    {
        if (!$current) { return $new; }
        return [
            'provider' => (string)($current['provider'] ?? $new['provider'] ?? ''),
            'model' => (string)($current['model'] ?? $new['model'] ?? ''),
            'input_tokens' => (int)($current['input_tokens'] ?? 0) + (int)($new['input_tokens'] ?? 0),
            'output_tokens' => (int)($current['output_tokens'] ?? 0) + (int)($new['output_tokens'] ?? 0),
            'total_tokens' => (int)($current['total_tokens'] ?? 0) + (int)($new['total_tokens'] ?? 0),
        ];
    }
}
