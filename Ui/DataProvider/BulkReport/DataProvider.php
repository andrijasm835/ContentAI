<?php
namespace Nistruct\ContentAI\Ui\DataProvider\BulkReport;
use Magento\Ui\DataProvider\AbstractDataProvider; use Nistruct\ContentAI\Model\ResourceModel\BulkReport\CollectionFactory;
class DataProvider extends AbstractDataProvider { public function __construct($name,$primaryFieldName,$requestFieldName,CollectionFactory $collectionFactory,array $meta=[],array $data=[]){$this->collection=$collectionFactory->create();parent::__construct($name,$primaryFieldName,$requestFieldName,$meta,$data);} }
