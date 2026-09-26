<?php

namespace Nistruct\ContentAI\Model\Query;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class QueryException extends LocalizedException
{
    public static function configuration(string $message): self { return new self(new Phrase($message)); }
    public static function authentication(): self { return new self(__('AI provider authentication failed. Check the configured API key.')); }
    public static function rateLimit(): self { return new self(__('The AI provider rate limit was reached. Please try again shortly.')); }
    public static function unavailable(): self { return new self(__('The AI provider is temporarily unavailable. Please try again later.')); }
    public static function network(): self { return new self(__('The AI provider request timed out or could not connect.')); }
    public static function invalidRequest(): self { return new self(__('The AI provider rejected the generation request.')); }
    public static function invalidResponse(): self { return new self(__('The AI provider returned an invalid response.')); }
    public static function noContent(): self { return new self(__('The AI provider returned no generated content.')); }
}
