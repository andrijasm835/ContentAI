<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Scope;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Nistruct\ContentAI\Model\Scope\LanguageResolver;
use PHPUnit\Framework\TestCase;

class LanguageResolverTest extends TestCase
{
    public function testDefaultAndConcreteLocalesAndGenericScript(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function (string $path, $scope = null, $scopeCode = null) {
            if ($path === LanguageResolver::XML_DEFAULT_LOCALE) {
                return 'en_US';
            }
            if ($path === 'general/locale/code' && (int)$scopeCode === 5) {
                return 'sr_Latn_RS';
            }
            return '';
        });
        $resolver = new LanguageResolver($config);
        self::assertSame('en_US', $resolver->getLocale(0));
        self::assertSame('sr_Latn_RS', $resolver->getLocale(5));
        self::assertSame('Serbian', $resolver->getLanguage('sr_Latn_RS'));
        self::assertSame('Latin', $resolver->getScript('sr_Latn_RS'));
        self::assertSame('Cyrillic', $resolver->getScript('sr_Cyrl_RS'));
    }
}
