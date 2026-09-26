<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Field;

use Magento\Framework\Exception\LocalizedException;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Field\ProductFieldRequestValidator;
use PHPUnit\Framework\TestCase;

class ProductFieldRequestValidatorTest extends TestCase
{
    public function testAllowedFieldsAreReturned(): void
    {
        $provider = $this->createMock(ProductFieldProvider::class);
        $provider->expects(self::once())->method('filterCodes')
            ->with(['description'])
            ->willReturn(['description']);

        self::assertSame(
            ['description'],
            (new ProductFieldRequestValidator($provider))->validateCodes(['description'])
        );
    }

    public function testCraftedDisallowedFieldIsRejected(): void
    {
        $provider = $this->createMock(ProductFieldProvider::class);
        $provider->expects(self::once())->method('filterCodes')
            ->with(['description', 'cost'])
            ->willReturn(['description']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cost');
        (new ProductFieldRequestValidator($provider))->validateCodes(['description', 'cost']);
    }
}
