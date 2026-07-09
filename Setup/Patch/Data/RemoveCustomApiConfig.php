<?php

namespace Nistruct\ContentAI\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class RemoveCustomApiConfig implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $configTable = $this->moduleDataSetup->getTable('core_config_data');

        $connection->startSetup();
        $connection->delete($configTable, ['path = ?' => 'contentai/api/api_endpoint']);
        $connection->update(
            $configTable,
            ['value' => 'openai'],
            ['path = ?' => 'contentai/api/provider', 'value = ?' => 'custom']
        );
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
