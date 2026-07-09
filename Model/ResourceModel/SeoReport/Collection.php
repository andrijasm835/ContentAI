<?php
namespace Nistruct\ContentAI\Model\ResourceModel\SeoReport;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \Nistruct\ContentAI\Model\SeoReport::class,
            \Nistruct\ContentAI\Model\ResourceModel\SeoReport::class
        );
    }
}
