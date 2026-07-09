<?php
namespace Nistruct\ContentAI\Model;

use Magento\Framework\Model\AbstractModel;

class Report extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Nistruct\ContentAI\Model\ResourceModel\Report::class);
    }
}
