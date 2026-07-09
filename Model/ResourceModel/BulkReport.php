<?php
namespace Nistruct\ContentAI\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class BulkReport extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('nistruct_contentai_bulk_report', 'entity_id');
    }
}
