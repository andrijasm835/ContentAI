<?php

namespace Nistruct\ContentAI\Test\Unit\Controller\Adminhtml\BulkReport;

use Nistruct\ContentAI\Controller\Adminhtml\BulkReport\Apply;
use PHPUnit\Framework\TestCase;

class ApplyTest extends TestCase
{
    public function testLegacyReportKeysKeepHistoricalTargets(): void
    {
        $targets = (new \ReflectionClass(Apply::class))->getConstant('LEGACY_FIELD_TARGETS');

        self::assertSame('product_subtitle', $targets['subtitle']);
        self::assertSame('tech_specs_features', $targets['features']);
    }
}
