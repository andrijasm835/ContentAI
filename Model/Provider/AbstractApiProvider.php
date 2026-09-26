<?php

namespace Nistruct\ContentAI\Model\Provider;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\Query\QueryException;
use Psr\Log\LoggerInterface;

abstract class AbstractApiProvider implements AiProviderInterface
{
    private const MAX_ATTEMPTS = 3;
    private const TRANSIENT_STATUSES = [429, 500, 502, 503, 504];
    protected Curl $curl;
    protected Json $json;
    protected HelperData $helper;
    protected LoggerInterface $logger;
    public function __construct(Curl $curl, Json $json, HelperData $helper, LoggerInterface $logger)
    {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    protected function request(string $endpoint, array $payload, array $headers): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->curl->setTimeout(30);
                $this->curl->setHeaders($headers);
                $this->logRequest($endpoint, $payload);
                $this->curl->post($endpoint, $this->json->serialize($payload));
                $status = (int)$this->curl->getStatus();
                $body = (string)$this->curl->getBody();
                $this->logResponse($status, $body);
                if ($status < 400) {
                    $this->curl->setHeaders([]);
                    return [$status, $body];
                }
                if (!in_array($status, self::TRANSIENT_STATUSES, true)) {
                    throw $this->httpException($status);
                }
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->logger->error(sprintf(
                        'ContentAI provider %s final failure, attempt %d, HTTP %d.',
                        $this->getCode(),
                        $attempt,
                        $status
                    ));
                    throw $this->httpException($status);
                }
                $this->logger->warning(sprintf(
                    'ContentAI provider %s transient failure, attempt %d, HTTP %d.',
                    $this->getCode(),
                    $attempt,
                    $status
                ));
                $this->sleepBeforeRetry($attempt, $this->getRetryAfterSeconds());
            } catch (QueryException $e) {
                $this->curl->setHeaders([]);
                $this->logger->error(sprintf(
                    'ContentAI provider %s final failure: %s',
                    $this->getCode(),
                    $e->getMessage()
                ));
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->logger->error(sprintf(
                        'ContentAI provider %s final network failure: %s',
                        $this->getCode(),
                        $e->getMessage()
                    ));
                    throw QueryException::network();
                }
                $this->logger->warning(sprintf(
                    'ContentAI provider %s network failure, attempt %d: %s',
                    $this->getCode(),
                    $attempt,
                    $e->getMessage()
                ));
                $this->sleepBeforeRetry($attempt);
            } finally {
                $this->curl->setHeaders([]);
            }
        }
        throw QueryException::unavailable();
    }
    protected function usage(string $model, array $response): array
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $input = (int)($usage['input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        return [
            'provider' => $this->getCode(),
            'model' => (string)($response['model'] ?? $model),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => (int)($usage['total_tokens'] ?? $input + $output),
        ];
    }
    protected function systemPrompt(): string
    {
        return 'You are an ecommerce product copywriter. Generate accurate, concise Magento catalog content '
            . 'from supplied product data. Never invent technical specifications, certifications, dimensions, '
            . 'prices, stock status, or claims not present in the prompt.';
    }
    protected function dataImage(string $url): ?array
    {
        return preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $url, $matches)
            ? ['media_type' => $matches[1], 'data' => $matches[2]]
            : null;
    }
    protected function httpImage(string $url): bool
    {
        return (bool)preg_match('#^https?://#i', $url);
    }
    private function httpException(int $status): QueryException
    {
        if (in_array($status, [401, 403], true)) {
            return QueryException::authentication();
        }
        if ($status === 429) {
            return QueryException::rateLimit();
        }
        if (in_array($status, [500, 502, 503, 504], true)) {
            return QueryException::unavailable();
        }
        return QueryException::invalidRequest();
    }
    protected function sleepBeforeRetry(int $attempt, int $retryAfter = 0): void
    {
        $microseconds = $retryAfter > 0 ? min($retryAfter, 10) * 1000000 : $attempt * 250000;
        usleep($microseconds);
    }

    private function getRetryAfterSeconds(): int
    {
        foreach ((array)$this->curl->getHeaders() as $name => $value) {
            if (strtolower((string)$name) === 'retry-after' && ctype_digit((string)$value)) {
                return (int)$value;
            }
        }
        return 0;
    }
    private function logRequest(string $endpoint, array $payload): void
    {
        if (!$this->helper->isDebugEnabled()) {
            return;
        }
        $this->logger->info(sprintf('ContentAI %s endpoint: %s', $this->getCode(), $endpoint));
        $this->logger->info(sprintf(
            'ContentAI %s payload: %s',
            $this->getCode(),
            $this->json->serialize($this->redact($payload))
        ));
    }
    private function logResponse(int $status, string $body): void
    {
        if (!$this->helper->isDebugEnabled()) {
            return;
        }
        $this->logger->info(sprintf('ContentAI %s HTTP status: %d', $this->getCode(), $status));
        $this->logger->info(sprintf('ContentAI %s response body: %s', $this->getCode(), $body));
    }
    private function redact($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = in_array((string)$key, ['data', 'image_url'], true)
                    ? '[redacted]'
                    : $this->redact($item);
            }
            return $value;
        }
        return $value;
    }
}
