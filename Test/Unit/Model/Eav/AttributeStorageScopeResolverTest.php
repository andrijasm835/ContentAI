<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Eav;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Website;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Model\Eav\AttributeStorageScopeResolver;
use PHPUnit\Framework\TestCase;

class AttributeStorageScopeResolverTest extends TestCase
{
    public function testGlobalWebsiteAndStoreStorageIds(): void
    {
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getId')->willReturn(3);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultStore')->willReturn($defaultStore);
        $targetStore = $this->createMock(Store::class);
        $targetStore->method('getWebsite')->willReturn($website);
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->with(5)->willReturn($targetStore);
        $resolver = new AttributeStorageScopeResolver($manager);
        $global = $this->attribute(true, false);
        $websiteAttribute = $this->attribute(false, true);
        $store = $this->attribute(false, false);
        self::assertSame(0, $resolver->resolve($global, 5));
        self::assertSame(3, $resolver->resolve($websiteAttribute, 5));
        self::assertSame(5, $resolver->resolve($store, 5));
        self::assertSame(0, $resolver->resolve($store, 0));
    }
    private function attribute(bool $global, bool $website)
    {
        $attribute = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)->disableOriginalConstructor()->onlyMethods(['isScopeGlobal','isScopeWebsite'])->getMock();
        $attribute->method('isScopeGlobal')->willReturn($global);
        $attribute->method('isScopeWebsite')->willReturn($website);
        return $attribute;
    }
}
