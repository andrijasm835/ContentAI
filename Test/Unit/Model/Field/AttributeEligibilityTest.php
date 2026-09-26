<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Field;

use Nistruct\ContentAI\Model\Field\AttributeEligibility;
use PHPUnit\Framework\TestCase;

class AttributeEligibilityTest extends TestCase
{
    /** @dataProvider cases */
    public function testSafeDiscovery(string $entity, string $code, string $input, string $backend, bool $expected): void
    {
        $attribute = $this->getMockBuilder(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class)->disableOriginalConstructor()->onlyMethods(['getAttributeCode','getFrontendInput','getBackendType'])->getMockForAbstractClass();
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backend);
        self::assertSame($expected, (new AttributeEligibility())->isEligible($entity, $attribute));
    }
    public function cases(): array
    {
        return [['catalog_product','custom_copy','textarea','text',true],['catalog_product','price','text','varchar',false],['catalog_product','custom_select','select','int',false],['catalog_category','url_key','text','varchar',false]];
    }
}
