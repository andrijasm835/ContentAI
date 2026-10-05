<?php
namespace Nistruct\ContentAI\Model\Seo;

class DuplicateValueAggregator
{
    public function getFlatDuplicateValueCounts(
        \Zend_Db_Adapter_Abstract $connection,
        \Zend_Db_Select $sourceSelect,
        string $field,
        string $idField
    ): array {
        $select = $this->buildFlatSelect($connection, $sourceSelect, $field, $idField);
        $counts = [];
        foreach ($connection->fetchPairs($select) as $value => $count) {
            $counts[(string) $value] = (int) $count;
        }

        return $counts;
    }

    public function getEavDuplicateValueCounts(
        \Zend_Db_Adapter_Abstract $connection,
        \Zend_Db_Select $sourceSelect,
        string $valueTable,
        int $attributeId,
        int $storeId,
        string $idField
    ): array {
        $select = $this->buildEavSelect($connection, $sourceSelect, $valueTable, $attributeId, $storeId, $idField);
        $counts = [];
        foreach ($connection->fetchPairs($select) as $value => $count) {
            $counts[(string) $value] = (int) $count;
        }

        return $counts;
    }

    public function buildFlatSelect(
        \Zend_Db_Adapter_Abstract $connection,
        \Zend_Db_Select $sourceSelect,
        string $field,
        string $idField
    ): \Zend_Db_Select {
        $baseSelect = clone $sourceSelect;
        $baseSelect->reset(\Zend_Db_Select::ORDER)
            ->reset(\Zend_Db_Select::LIMIT_COUNT)
            ->reset(\Zend_Db_Select::LIMIT_OFFSET);

        $fieldExpr = 'LOWER(TRIM(' . $connection->quoteIdentifier('dup.' . $field) . '))';
        $idExpr = $connection->quoteIdentifier('dup.' . $idField);

        return $connection->select()
            ->from(
                ['dup' => $baseSelect],
                [
                    'normalized_value' => new \Zend_Db_Expr($fieldExpr),
                    'entity_count' => new \Zend_Db_Expr('COUNT(DISTINCT ' . $idExpr . ')'),
                ]
            )
            ->where($connection->quoteIdentifier('dup.' . $field) . ' IS NOT NULL')
            ->where($fieldExpr . " <> ''")
            ->group(new \Zend_Db_Expr($fieldExpr))
            ->having('COUNT(DISTINCT ' . $idExpr . ') > 1');
    }

    public function buildEavSelect(
        \Zend_Db_Adapter_Abstract $connection,
        \Zend_Db_Select $sourceSelect,
        string $valueTable,
        int $attributeId,
        int $storeId,
        string $idField
    ): \Zend_Db_Select {
        $baseSelect = clone $sourceSelect;
        $baseSelect->reset(\Zend_Db_Select::ORDER)
            ->reset(\Zend_Db_Select::LIMIT_COUNT)
            ->reset(\Zend_Db_Select::LIMIT_OFFSET);

        $idExpr = $connection->quoteIdentifier('dup.' . $idField);
        $effectiveValueExpr = 'COALESCE('
            . $connection->quoteIdentifier('store_value.value')
            . ', '
            . $connection->quoteIdentifier('default_value.value')
            . ')';
        $normalizedExpr = 'LOWER(TRIM(' . $effectiveValueExpr . '))';

        return $connection->select()
            ->from(
                ['dup' => $baseSelect],
                [
                    'normalized_value' => new \Zend_Db_Expr($normalizedExpr),
                    'entity_count' => new \Zend_Db_Expr('COUNT(DISTINCT ' . $idExpr . ')'),
                ]
            )
            ->joinLeft(
                ['default_value' => $valueTable],
                $connection->quoteIdentifier('default_value.entity_id') . ' = ' . $idExpr
                    . ' AND ' . $connection->quoteIdentifier('default_value.attribute_id') . ' = ' . (int) $attributeId
                    . ' AND ' . $connection->quoteIdentifier('default_value.store_id') . ' = 0',
                []
            )
            ->joinLeft(
                ['store_value' => $valueTable],
                $connection->quoteIdentifier('store_value.entity_id') . ' = ' . $idExpr
                    . ' AND ' . $connection->quoteIdentifier('store_value.attribute_id') . ' = ' . (int) $attributeId
                    . ' AND ' . $connection->quoteIdentifier('store_value.store_id') . ' = ' . (int) $storeId,
                []
            )
            ->where($effectiveValueExpr . ' IS NOT NULL')
            ->where($normalizedExpr . " <> ''")
            ->group(new \Zend_Db_Expr($normalizedExpr))
            ->having('COUNT(DISTINCT ' . $idExpr . ') > 1');
    }
}
