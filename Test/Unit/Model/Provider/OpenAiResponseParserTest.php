<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Provider;

use Nistruct\ContentAI\Model\Provider\OpenAiResponseParser;
use PHPUnit\Framework\TestCase;

class OpenAiResponseParserTest extends TestCase
{
    public function testOutputTextTakesPrecedenceOverStructuredOutput(): void
    {
        $response = [
            'output_text' => 'Generated once',
            'output' => [[
                'content' => [['text' => 'Generated once']],
            ]],
        ];

        self::assertSame('Generated once', (new OpenAiResponseParser())->extract($response));
    }

    public function testStructuredOutputIsFallbackWhenOutputTextIsEmpty(): void
    {
        $response = [
            'output_text' => '  ',
            'output' => [[
                'content' => [['text' => 'First'], ['text' => 'Second']],
            ]],
        ];

        self::assertSame("First\nSecond", (new OpenAiResponseParser())->extract($response));
    }
}
