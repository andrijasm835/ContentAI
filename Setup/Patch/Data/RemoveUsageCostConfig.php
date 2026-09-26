<?php
namespace Nistruct\ContentAI\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class RemoveUsageCostConfig implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $connection->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['path IN (?)' => [
                'contentai/api/openai_input_price',
                'contentai/api/openai_output_price',
                'contentai/api/anthropic_input_price',
                'contentai/api/anthropic_output_price',
            ]]
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
