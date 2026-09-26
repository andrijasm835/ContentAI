<?php

namespace Nistruct\ContentAI\Model\Provider;

use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Query\QueryException;

class OpenAiProvider extends AbstractApiProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';
    private OpenAiResponseParser $responseParser;

    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\Serialize\Serializer\Json $json,
        HelperData $helper,
        \Psr\Log\LoggerInterface $logger,
        OpenAiResponseParser $responseParser
    ) {
        parent::__construct($curl, $json, $helper, $logger);
        $this->responseParser = $responseParser;
    }

    public function getCode(): string
    {
        return HelperData::PROVIDER_OPENAI;
    }
    public function generate(string $prompt, string $imageUrl = ''): AiResponse
    {
        $key = $this->helper->getOpenAiApiKey();
        if ($key === '') {
            throw QueryException::configuration('OpenAI API key is not configured.');
        }
        $content = [];
        if ($this->dataImage($imageUrl) || $this->httpImage($imageUrl)) {
            $content[] = ['type' => 'input_image', 'image_url' => $imageUrl, 'detail' => 'low'];
        }
        $content[] = ['type' => 'input_text', 'text' => $prompt];
        $model = $this->helper->getOpenAiModel();
        $payload = [
            'model' => $model,
            'max_output_tokens' => $this->helper->getOpenAiMaxTokens(),
            'instructions' => $this->systemPrompt(),
            'input' => [['role' => 'user', 'content' => $content]],
        ];
        [, $body] = $this->request(
            self::ENDPOINT,
            $payload,
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]
        );
        try {
            $response = $this->json->unserialize($body);
        } catch (\Throwable $e) {
            throw QueryException::invalidResponse();
        }
        $text = $this->responseParser->extract($response);
        if ($text === '') {
            throw QueryException::noContent();
        }
        return new AiResponse($text, $this->usage($model, $response));
    }
}
