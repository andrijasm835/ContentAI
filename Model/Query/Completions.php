<?php
namespace Nistruct\ContentAI\Model\Query;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Psr\Log\LoggerInterface;

class Completions
{
    private const HTTP_TIMEOUT = 30;
    private const ANTHROPIC_MESSAGES_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const OPENAI_RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

    private $curl;
    private $json;
    private $logger;
    private $helper;
    private $usageMetadata = [];

    public function __construct(
        Curl $curl,
        Json $json,
        LoggerInterface $logger,
        HelperData $helper
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function generateContent(string $prompt, string $imageUrl = ''): string
    {
        if ($this->helper->getApiProvider() === HelperData::PROVIDER_ANTHROPIC) {
            return $this->generateAnthropicContent($prompt, $imageUrl);
        }

        return $this->generateOpenAiContent($prompt, $imageUrl);
    }

    public function resetUsageMetadata(): void
    {
        $this->usageMetadata = [];
    }

    public function getUsageMetadata(): array
    {
        return $this->usageMetadata;
    }

    private function generateAnthropicContent(string $prompt, string $imageUrl = ''): string
    {
        $apiKey = $this->helper->getAnthropicApiKey();
        if ($apiKey === '') {
            throw new LocalizedException(new Phrase('Anthropic API key is not configured.'));
        }

        $payload = [
            'model' => $this->helper->getAnthropicModel(),
            'max_tokens' => $this->helper->getAnthropicMaxTokens(),
            'system' => $this->getSystemPrompt(),
            'messages' => [[
                'role' => 'user',
                'content' => $this->buildAnthropicUserContent($prompt, $imageUrl)
            ]]
        ];

        try {
            [$status, $body] = $this->requestJson(self::ANTHROPIC_MESSAGES_ENDPOINT, $payload, 'ContentAI Anthropic', [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION
            ]);
            $this->logResponse('ContentAI Anthropic', $status, $body);
            if ($status >= 400) {
                throw new LocalizedException(new Phrase("Anthropic API returned HTTP $status"));
            }
            $response = $this->json->unserialize($body);
            $this->recordUsageMetadata(HelperData::PROVIDER_ANTHROPIC, $this->helper->getAnthropicModel(), $response);
            $content = $this->extractAnthropicText($response);
            if ($content === '') {
                throw new LocalizedException(new Phrase('No content returned from Anthropic API'));
            }
            return $content;
        } catch (\Exception $e) {
            $this->logger->error('ContentAI Anthropic error: ' . $e->getMessage());
            throw new LocalizedException(new Phrase('Error generating Claude content: %1', [$e->getMessage()]));
        }
    }

    private function generateOpenAiContent(string $prompt, string $imageUrl = ''): string
    {
        $apiKey = $this->helper->getOpenAiApiKey();
        if ($apiKey === '') {
            throw new LocalizedException(new Phrase('OpenAI API key is not configured.'));
        }

        $payload = [
            'model' => $this->helper->getOpenAiModel(),
            'max_output_tokens' => $this->helper->getOpenAiMaxTokens(),
            'instructions' => $this->getSystemPrompt(),
            'input' => $this->buildOpenAiInput($prompt, $imageUrl),
            'text' => ['verbosity' => 'low']
        ];

        try {
            [$status, $body] = $this->requestJson(self::OPENAI_RESPONSES_ENDPOINT, $payload, 'ContentAI OpenAI', [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey
            ]);
            $this->logResponse('ContentAI OpenAI', $status, $body);
            if ($status >= 400) {
                throw new LocalizedException(new Phrase("OpenAI API returned HTTP $status"));
            }
            $response = $this->json->unserialize($body);
            $this->recordUsageMetadata(HelperData::PROVIDER_OPENAI, $this->helper->getOpenAiModel(), $response);
            $content = $this->extractOpenAiText($response);
            if ($content === '') {
                throw new LocalizedException(new Phrase('No content returned from OpenAI API'));
            }
            return $content;
        } catch (\Exception $e) {
            $this->logger->error('ContentAI OpenAI error: ' . $e->getMessage());
            throw new LocalizedException(new Phrase('Error generating GPT content: %1', [$e->getMessage()]));
        }
    }

    private function requestJson(string $endpoint, array $payload, string $label, array $headers = ['Content-Type' => 'application/json']): array
    {
        $this->curl->setTimeout(self::HTTP_TIMEOUT);
        $this->curl->setHeaders($headers);
        try {
            $this->logRequest($label, $endpoint, $payload);
            $this->curl->post($endpoint, $this->json->serialize($payload));
            return [$this->curl->getStatus(), $this->curl->getBody()];
        } finally {
            $this->curl->setHeaders([]);
        }
    }

    private function buildAnthropicUserContent(string $prompt, string $imageUrl): array
    {
        $content = [];
        $dataImage = $this->parseDataImageUrl($imageUrl);
        if ($dataImage) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $dataImage['media_type'],
                    'data' => $dataImage['data']
                ]
            ];
        } elseif ($this->isHttpImageUrl($imageUrl)) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $imageUrl]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        return $content;
    }

    private function buildOpenAiInput(string $prompt, string $imageUrl): array
    {
        $content = [];
        if ($this->parseDataImageUrl($imageUrl) || $this->isHttpImageUrl($imageUrl)) {
            $content[] = ['type' => 'input_image', 'image_url' => $imageUrl, 'detail' => 'low'];
        }
        $content[] = ['type' => 'input_text', 'text' => $prompt];
        return [['role' => 'user', 'content' => $content]];
    }

    private function parseDataImageUrl(string $imageUrl): ?array
    {
        if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $imageUrl, $matches)) {
            return null;
        }
        return ['media_type' => $matches[1], 'data' => $matches[2]];
    }

    private function isHttpImageUrl(string $imageUrl): bool
    {
        return (bool) preg_match('#^https?://#i', $imageUrl);
    }

    private function extractAnthropicText(array $response): string
    {
        $parts = [];
        foreach (($response['content'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $parts[] = (string) $item['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    private function extractOpenAiText(array $response): string
    {
        if (isset($response['output_text'])) {
            return trim((string) $response['output_text']);
        }
        $parts = [];
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (isset($content['text'])) {
                    $parts[] = (string) $content['text'];
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function getSystemPrompt(): string
    {
        return 'You are an ecommerce product copywriter. Generate accurate, concise Magento catalog content from supplied product data. Never invent technical specifications, certifications, dimensions, prices, stock status, or claims not present in the prompt.';
    }

    private function recordUsageMetadata(string $provider, string $configuredModel, array $response): void
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        $metadata = [
            'provider' => $provider,
            'model' => (string) ($response['model'] ?? $configuredModel),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $this->helper->estimateTokenCost($provider, $inputTokens, $outputTokens),
            'currency' => 'USD',
        ];

        $this->usageMetadata = $this->mergeUsageMetadata($this->usageMetadata, $metadata);
    }

    private function mergeUsageMetadata(array $current, array $new): array
    {
        if (!$current) {
            return $new;
        }

        return [
            'provider' => (string) ($current['provider'] ?? $new['provider'] ?? ''),
            'model' => (string) ($current['model'] ?? $new['model'] ?? ''),
            'input_tokens' => (int) ($current['input_tokens'] ?? 0) + (int) ($new['input_tokens'] ?? 0),
            'output_tokens' => (int) ($current['output_tokens'] ?? 0) + (int) ($new['output_tokens'] ?? 0),
            'total_tokens' => (int) ($current['total_tokens'] ?? 0) + (int) ($new['total_tokens'] ?? 0),
            'estimated_cost' => (float) ($current['estimated_cost'] ?? 0) + (float) ($new['estimated_cost'] ?? 0),
            'currency' => (string) ($current['currency'] ?? $new['currency'] ?? 'USD'),
        ];
    }

    private function logRequest(string $label, string $endpoint, array $payload): void
    {
        if (!$this->helper->isDebugEnabled()) {
            return;
        }
        if (isset($payload['input'][0]['content'][0]['image_url'])) {
            $payload['input'][0]['content'][0]['image_url'] = '[redacted]';
        }
        if (isset($payload['messages'][0]['content'][0]['source']['data'])) {
            $payload['messages'][0]['content'][0]['source']['data'] = '[redacted]';
        }
        $this->logger->info($label . ' endpoint: ' . $endpoint);
        $this->logger->info($label . ' payload: ' . $this->json->serialize($payload));
    }

    private function logResponse(string $label, int $status, string $body): void
    {
        if (!$this->helper->isDebugEnabled()) {
            return;
        }
        $this->logger->info($label . ' HTTP status: ' . $status);
        $this->logger->info($label . ' response body: ' . $body);
    }
}
