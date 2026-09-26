<?php

namespace Nistruct\ContentAI\Model\Provider;

class OpenAiResponseParser
{
    public function extract(array $response): string
    {
        $outputText = trim((string)($response['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $parts = [];
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $item) {
                $text = trim((string)($item['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
