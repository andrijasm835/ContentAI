<?php

namespace Nistruct\ContentAI\Model\Provider;

use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Query\QueryException;

class AnthropicProvider extends AbstractApiProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public function getCode(): string
    {
        return HelperData::PROVIDER_ANTHROPIC;
    }
    public function generate(string $prompt, string $imageUrl = ''): AiResponse
    {
        $key = $this->helper->getAnthropicApiKey();
        if ($key === '') {
            throw QueryException::configuration('Anthropic API key is not configured.');
        }
        $content = [];
        $image = $this->dataImage($imageUrl);
        if ($image) {
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']],
            ];
        } elseif ($this->httpImage($imageUrl)) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $imageUrl]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        $model = $this->helper->getAnthropicModel();
        $payload = [
            'model' => $model,
            'max_tokens' => $this->helper->getAnthropicMaxTokens(),
            'system' => $this->systemPrompt(),
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        [, $body] = $this->request(
            self::ENDPOINT,
            $payload,
            ['Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01']
        );
        try {
            $response = $this->json->unserialize($body);
        } catch (\Throwable $e) {
            throw QueryException::invalidResponse();
        }
        $parts = [];
        foreach (($response['content'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $parts[] = (string)$item['text'];
            }
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw QueryException::noContent();
        }
        return new AiResponse($text, $this->usage($model, $response));
    }
}
