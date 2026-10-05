<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Seo;

use Nistruct\ContentAI\Model\Seo\DuplicateValueAggregator;
use PHPUnit\Framework\TestCase;

class DuplicateValueAggregatorTest extends TestCase
{
    public function testBuildSelectAggregatesDuplicatesWithoutSourcePagination(): void
    {
        $connection = $this->createAdapter();
        $sourceSelect = $connection->select()
            ->from('cms_page', ['page_id', 'meta_title'])
            ->where('meta_title IS NOT NULL')
            ->order('page_id ASC')
            ->limit(20, 40);

        $sql = (string) (new DuplicateValueAggregator())->buildFlatSelect($connection, $sourceSelect, 'meta_title', 'page_id');

        self::assertStringContainsString('LOWER(TRIM(`dup`.`meta_title`))', $sql);
        self::assertStringContainsString('COUNT(DISTINCT `dup`.`page_id`)', $sql);
        self::assertStringContainsString("LOWER(TRIM(`dup`.`meta_title`)) <> ''", $sql);
        self::assertStringContainsString('HAVING (COUNT(DISTINCT `dup`.`page_id`) > 1)', $sql);
        self::assertStringNotContainsString('LIMIT 20', $sql);
        self::assertStringNotContainsString('OFFSET 40', $sql);
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testBuildEavSelectUsesStoreFallbackAndDistinctEntities(): void
    {
        $connection = $this->createAdapter();
        $sourceSelect = $connection->select()
            ->from('catalog_product_entity', ['entity_id'])
            ->join(['category_index' => 'catalog_category_product_index'], 'category_index.product_id = catalog_product_entity.entity_id', [])
            ->order('entity_id ASC')
            ->limit(50, 100);

        $sql = (string) (new DuplicateValueAggregator())->buildEavSelect(
            $connection,
            $sourceSelect,
            'catalog_product_entity_varchar',
            73,
            5,
            'entity_id'
        );

        self::assertStringContainsString('COALESCE(`store_value`.`value`, `default_value`.`value`)', $sql);
        self::assertStringContainsString('`default_value`.`store_id` = 0', $sql);
        self::assertStringContainsString('`store_value`.`store_id` = 5', $sql);
        self::assertStringContainsString('COUNT(DISTINCT `dup`.`entity_id`)', $sql);
        self::assertStringContainsString("LOWER(TRIM(COALESCE(`store_value`.`value`, `default_value`.`value`))) <> ''", $sql);
        self::assertStringNotContainsString('LIMIT 50', $sql);
        self::assertStringNotContainsString('OFFSET 100', $sql);
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    private function createAdapter(): \Zend_Db_Adapter_Abstract
    {
        return new class extends \Zend_Db_Adapter_Abstract {
            protected $_fetchMode = \Zend_Db::FETCH_ASSOC;

            public function listTables()
            {
                return [];
            }

            public function describeTable($tableName, $schemaName = null)
            {
                return [];
            }

            public function closeConnection()
            {
            }

            public function prepare($sql)
            {
                return $sql;
            }

            public function lastInsertId($tableName = null, $primaryKey = null)
            {
                return '0';
            }

            public function limit($sql, $count, $offset = 0)
            {
                return $sql . ' LIMIT ' . (int) $count . ($offset ? ' OFFSET ' . (int) $offset : '');
            }

            public function supportsParameters($type)
            {
                return false;
            }

            public function getServerVersion()
            {
                return '8.0';
            }

            protected function _connect()
            {
            }

            protected function _quote($value)
            {
                return "'" . str_replace("'", "''", (string) $value) . "'";
            }

            public function quoteIdentifier($ident, $auto = false)
            {
                if ($ident instanceof \Zend_Db_Expr) {
                    return (string) $ident;
                }

                return implode('.', array_map(static function (string $part): string {
                    return '`' . str_replace('`', '``', $part) . '`';
                }, explode('.', (string) $ident)));
            }
        };
    }
}
