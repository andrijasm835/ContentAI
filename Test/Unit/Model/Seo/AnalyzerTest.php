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

    public function testOnlyInformationalFindingsAreNotActionableProblems(): void
    {
        $metrics = new AuditMetrics();
        $analyzer = $this->createAnalyzerWithTableResolver($metrics);
        $sectionMethod = new ReflectionMethod($analyzer, 'section');
        $sectionMethod->setAccessible(true);
        $summaryMethod = new ReflectionMethod($analyzer, 'summarize');
        $summaryMethod->setAccessible(true);

        $section = $sectionMethod->invoke($analyzer, 'products', 'Products', [[
            'type' => 'product',
            'identifier' => 'SKU-1',
            'label' => 'Product One',
            'issues' => [$metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'legacy_redirects_present', '', '')],
        ]], 1, 0, [], 1);
        $report = $summaryMethod->invoke($analyzer, 'products', 1, 100, 0, [], ['products' => $section]);

        self::assertSame(0, $section['total_issues']);
        self::assertSame(1, $section['informational_count']);
        self::assertSame('no_issues', $section['status']);
        self::assertSame('ok', $section['items'][0]['priority']);
        self::assertSame(100, $section['items'][0]['health_score']);
        self::assertSame('No SEO action needed.', $section['items'][0]['next_action']);
        self::assertSame(0, $report['summary']['total_issues']);
        self::assertSame('no_issues', $report['summary']['status']);
        self::assertSame([], $report['summary']['top_issues']);
        self::assertSame(['legacy_redirects_present' => 1], $report['summary']['informational_notes']);
    }

    public function testActionableAndInformationalFindingsKeepActionableProblem(): void
    {
        $metrics = new AuditMetrics();
        $analyzer = $this->createAnalyzerWithTableResolver($metrics);
        $sectionMethod = new ReflectionMethod($analyzer, 'section');
        $sectionMethod->setAccessible(true);

        $section = $sectionMethod->invoke($analyzer, 'products', 'Products', [[
            'type' => 'product',
            'identifier' => 'SKU-1',
            'label' => 'Product One',
            'issues' => [
                $metrics->issue(AuditMetrics::SEVERITY_WARNING, 'long_meta_title', '', ''),
                $metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'legacy_redirects_present', '', ''),
            ],
        ]], 1, 0, [], 1);

        self::assertSame(1, $section['total_issues']);
        self::assertSame(1, $section['warning_count']);
        self::assertSame(1, $section['informational_count']);
        self::assertSame(['long_meta_title' => 1], $section['issue_codes']);
        self::assertSame(['legacy_redirects_present' => 1], $section['informational_notes']);
        self::assertSame(1, $section['items'][0]['actionable_issue_count']);
    }

    private function createAnalyzerWithTableResolver(?AuditMetrics $metrics = null): Analyzer
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
            $metrics ?: new AuditMetrics()
        );
    }
}
