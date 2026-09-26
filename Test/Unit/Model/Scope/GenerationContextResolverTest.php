<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Scope;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Model\Scope\GenerationContextResolver;
use Nistruct\ContentAI\Model\Scope\LanguageResolver;
use PHPUnit\Framework\TestCase;

class GenerationContextResolverTest extends TestCase
{
    public function testDefaultScopeRemainsStoreZero(): void
    {
        $manager = $this->createMock(StoreManagerInterface::class);
        $language = $this->languageResolver(0, 'en_US', 'English', '');
        $context = (new GenerationContextResolver($manager, $language))->resolve(0);
        self::assertTrue($context->isDefaultScope());
        self::assertSame(0, $context->getTargetStoreId());
        self::assertSame('en_US', $context->getLocale());
    }

    public function testConcreteStoreProvidesWebsiteAndGroup(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $store->method('getStoreGroupId')->willReturn(4);
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->with(5)->willReturn($store);
        $language = $this->languageResolver(5, 'sr_Latn_RS', 'Serbian', 'Latin');
        $context = (new GenerationContextResolver($manager, $language))->resolve(5, [2]);
        self::assertSame(2, $context->getWebsiteId());
        self::assertSame(4, $context->getStoreGroupId());
        self::assertSame('Serbian', $context->getLanguage());
        self::assertSame('Latin', $context->getScript());
    }

    private function languageResolver(int $storeId, string $locale, string $language, string $script): LanguageResolver
    {
        $resolver = $this->createMock(LanguageResolver::class);
        $resolver->method('getLocale')->with($storeId)->willReturn($locale);
        $resolver->method('getLanguage')->with($locale)->willReturn($language);
        $resolver->method('getScript')->with($locale)->willReturn($script);
        $resolver->method('getInstruction')->with($storeId)->willReturn('');
        return $resolver;
    }
}
