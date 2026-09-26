<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Apply;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Helper\Data;
use Nistruct\ContentAI\Model\Apply\ProductAttributeApplyService;
use Nistruct\ContentAI\Model\Eav\AttributeStorageScopeResolver;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Media\ImageLabelService;
use PHPUnit\Framework\TestCase;

class ProductAttributeApplyServiceTest extends TestCase
{
    public function testFieldOutsideProductAttributeSetIsNotApplied(): void
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttributeSetId'])
            ->getMock();
        $product->method('getAttributeSetId')->willReturn(27);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($product);
        $fieldProvider = $this->createMock(ProductFieldProvider::class);
        $fieldProvider->method('getField')->with('custom_copy')->willReturn([
            'code' => 'custom_copy',
            'scope' => 'store',
        ]);
        $fieldProvider->method('isFieldInAttributeSet')->with('custom_copy', 27)->willReturn(false);
        $productAction = $this->createMock(ProductAction::class);
        $productAction->expects(self::never())->method('updateAttributes');
        $imageLabels = $this->createMock(ImageLabelService::class);
        $imageLabels->expects(self::never())->method('apply');

        $service = new ProductAttributeApplyService(
            $repository,
            $productAction,
            $fieldProvider,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Data::class),
            $this->createMock(AttributeStorageScopeResolver::class),
            $imageLabels,
            $this->createMock(CacheInterface::class)
        );

        self::assertSame(
            ['saved' => [], 'skipped' => []],
            $service->apply('sku', ['custom_copy' => 'Generated'], 1)
        );
    }
}
