<?php
namespace Nistruct\ContentAI\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SeoReport extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('nistruct_contentai_seo_report', 'entity_id');
    }
}
