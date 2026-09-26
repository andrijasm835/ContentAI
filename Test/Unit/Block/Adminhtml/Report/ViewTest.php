<?php

namespace Nistruct\ContentAI\Test\Unit\Block\Adminhtml\Report;

use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Registry;
use Nistruct\ContentAI\Block\Adminhtml\Report\View;
use Nistruct\ContentAI\Model\Report;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    public function testNewEavFieldsDoNotCreateLegacyRows(): void
    {
        $block = $this->createBlock([
            'product_subtitle' => 'Generated subtitle',
            'tech_specs_features' => 'Generated features',
        ]);

        self::assertSame(
            [
                'product_subtitle' => 'Generated subtitle',
                'tech_specs_features' => 'Generated features',
            ],
            $block->getExpectedFieldRows()
        );
        self::assertArrayNotHasKey('subtitle', $block->getExpectedFieldRows());
        self::assertArrayNotHasKey('features', $block->getExpectedFieldRows());
    }

    public function testLegacyFieldsStillRenderWhenStored(): void
    {
        $block = $this->createBlock([
            'subtitle' => 'Legacy subtitle',
            'features' => 'Legacy features',
        ]);

        self::assertSame('Legacy subtitle', $block->getExpectedFieldRows()['subtitle']);
        self::assertSame('Legacy features', $block->getExpectedFieldRows()['features']);
        self::assertSame('Subtitle', $block->getFieldLabel('subtitle'));
        self::assertSame('Features', $block->getFieldLabel('features'));
    }

    public function testCustomAttributeUsesMagentoFrontendLabel(): void
    {
        $attribute = $this->getMockBuilder(AbstractAttribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttributeId', 'getDefaultFrontendLabel'])
            ->getMockForAbstractClass();
        $attribute->method('getAttributeId')->willReturn(42);
        $attribute->method('getDefaultFrontendLabel')->willReturn('Material Composition');
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->expects(self::once())->method('getAttribute')
            ->with('catalog_product', 'material_composition')
            ->willReturn($attribute);

        self::assertSame(
            'Material Composition',
            $this->createBlock(['material_composition' => 'Paper'], $eavConfig)
                ->getFieldLabel('material_composition')
        );
    }

    private function createBlock(array $fields, ?EavConfig $eavConfig = null): View
    {
        $report = $this->getMockBuilder(Report::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $report->method('getData')->willReturnCallback(function (string $key) use ($fields) {
            return [
                'entity_type' => 'product',
                'generated_content' => json_encode($fields),
                'ai_description' => null,
            ][$key] ?? null;
        });
        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->with('current_contentai_report')->willReturn($report);

        return new View(
            $this->createMock(Context::class),
            $registry,
            $this->createMock(ProductRepositoryInterface::class),
            $eavConfig ?: $this->createMock(EavConfig::class)
        );
    }
}
