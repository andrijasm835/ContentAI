<?php

namespace Nistruct\ContentAI\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class BackfillGeneratedContent implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $reportTable = $this->moduleDataSetup->getTable('nistruct_contentai_report');

        $connection->startSetup();
        if (
            $connection->isTableExists($reportTable)
            && $connection->tableColumnExists($reportTable, 'generated_content')
            && $connection->tableColumnExists($reportTable, 'ai_description')
        ) {
            $connection->update(
                $reportTable,
                ['generated_content' => new \Zend_Db_Expr('ai_description')],
                ['generated_content IS NULL', 'ai_description IS NOT NULL']
            );
        }
        $connection->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
