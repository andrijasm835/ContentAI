<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Provider;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Nistruct\ContentAI\Helper\Data;
use Nistruct\ContentAI\Model\Provider\AbstractApiProvider;
use Nistruct\ContentAI\Model\Provider\AiResponse;
use Nistruct\ContentAI\Model\Query\QueryException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProviderRetryTest extends TestCase
{
    public function testTransientStatusIsRetried(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects(self::exactly(2))->method('post');
        $curl->method('getStatus')->willReturnOnConsecutiveCalls(429, 200);
        $curl->method('getBody')->willReturn('{}');
        $curl->method('getHeaders')->willReturn(['Retry-After' => '1']);
        [$status] = $this->provider($curl)->requestForTest();
        self::assertSame(200, $status);
    }

    public function testAuthenticationFailureIsNotRetried(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects(self::once())->method('post');
        $curl->method('getStatus')->willReturn(401);
        $curl->method('getBody')->willReturn('{}');
        $this->expectException(QueryException::class);
        $this->provider($curl)->requestForTest();
    }

    private function provider(Curl $curl): AbstractApiProvider
    {
        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('{}');
        $helper = $this->createMock(Data::class);
        $helper->method('isDebugEnabled')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        return new class ($curl, $json, $helper, $logger) extends AbstractApiProvider {
            public function getCode(): string { return 'test'; }
            public function generate(string $prompt, string $imageUrl = ''): AiResponse { return new AiResponse(''); }
            public function requestForTest(): array { return $this->request('https://example.invalid', [], []); }
            protected function sleepBeforeRetry(int $attempt, int $retryAfter = 0): void {}
        };
    }
}
