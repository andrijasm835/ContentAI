<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Seo;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Nistruct\ContentAI\Model\Seo\Analyzer;
use Nistruct\ContentAI\Model\Seo\AuditMetrics;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AnalyzerTest extends TestCase
{
    public function testMissingMetaKeywordsCreateNoNormalIssue(): void
    {
        $analyzer = new Analyzer(
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(CategoryCollectionFactory::class),
            $this->createMock(PageCollectionFactory::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(StoreManagerInterface::class),
            new AuditMetrics()
        );
        $method = new ReflectionMethod($analyzer, 'analyzeSeoFields');
        $method->setAccessible(true);

        $issues = $method->invoke(
            $analyzer,
            'product',
            'SKU-1',
            'Sample Product',
            'sample-product',
            'Useful Sample Product Title',
            'This is a useful sample product description with enough detail for a search result.',
            ''
        );

        self::assertSame([], array_column($issues, 'code'));
    }

    public function testEavValueTableNameUsesResolvedBackendType(): void
    {
        $analyzer = $this->createAnalyzerWithTableResolver();
        $method = new ReflectionMethod($analyzer, 'getEavValueTableName');
        $method->setAccessible(true);

        self::assertSame(
            'catalog_product_entity_varchar',
            $method->invoke($analyzer, 'catalog_product_entity', 'varchar')
        );
        self::assertSame(
            'catalog_product_entity_text',
            $method->invoke($analyzer, 'catalog_product_entity', 'text')
        );
        self::assertSame(
            'catalog_category_entity_varchar',
            $method->invoke($analyzer, 'catalog_category_entity', 'varchar')
        );
        self::assertSame(
            'catalog_category_entity_text',
            $method->invoke($analyzer, 'catalog_category_entity', 'text')
        );
    }

    public function testUnsupportedEavBackendTypeReturnsNoTable(): void
    {
        $analyzer = $this->createAnalyzerWithTableResolver();
        $method = new ReflectionMethod($analyzer, 'getEavValueTableName');
        $method->setAccessible(true);

        self::assertSame('', $method->invoke($analyzer, 'catalog_product_entity', 'static'));
        self::assertSame('', $method->invoke($analyzer, 'catalog_product_entity', ''));
        self::assertSame('', $method->invoke($analyzer, 'catalog_product_entity', 'varchar;drop table'));
    }

    private function createAnalyzerWithTableResolver(): Analyzer
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getTableName')->willReturnCallback(static function (string $table): string {
            return $table;
        });

        return new Analyzer(
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(CategoryCollectionFactory::class),
            $this->createMock(PageCollectionFactory::class),
            $resourceConnection,
            $this->createMock(StoreManagerInterface::class),
            new AuditMetrics()
        );
    }
}
