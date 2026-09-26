<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Provider;

use Nistruct\ContentAI\Model\Provider\AiProviderInterface;
use Nistruct\ContentAI\Model\Provider\ProviderPool;
use Nistruct\ContentAI\Model\Query\QueryException;
use PHPUnit\Framework\TestCase;

class ProviderPoolTest extends TestCase
{
    public function testConfiguredProviderIsResolved(): void
    {
        $provider = $this->createMock(AiProviderInterface::class);
        self::assertSame($provider, (new ProviderPool(['test' => $provider]))->get('test'));
    }
    public function testUnknownProviderIsRejected(): void
    {
        $this->expectException(QueryException::class);
        (new ProviderPool())->get('missing');
    }
}
