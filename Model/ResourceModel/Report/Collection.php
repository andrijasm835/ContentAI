<?php
namespace Nistruct\ContentAI\Model\ResourceModel\Report;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(\Nistruct\ContentAI\Model\Report::class, \Nistruct\ContentAI\Model\ResourceModel\Report::class);
    }
}
