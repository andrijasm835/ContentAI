<?php
namespace Nistruct\ContentAI\Ui\DataProvider\SeoReport;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Nistruct\ContentAI\Model\ResourceModel\SeoReport\CollectionFactory;

class DataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }
}
