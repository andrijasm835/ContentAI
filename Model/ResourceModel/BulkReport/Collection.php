<?php
namespace Nistruct\ContentAI\Model\ResourceModel\BulkReport;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(\Nistruct\ContentAI\Model\BulkReport::class, \Nistruct\ContentAI\Model\ResourceModel\BulkReport::class);
    }
}
