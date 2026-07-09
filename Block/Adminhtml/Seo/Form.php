<?php
namespace Nistruct\ContentAI\Block\Adminhtml\Seo;

use Magento\Backend\Block\Template;
use Magento\Store\Model\System\Store as SystemStore;

class Form extends Template
{
    private SystemStore $systemStore;

    public function __construct(
        Template\Context $context,
        SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->systemStore = $systemStore;
    }

    public function getPostUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seo/run');
    }

    public function getReportUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seoreport/index');
    }

    public function getStoreOptions(): array
    {
        $options = [['value' => 0, 'label' => __('All Store Views')]];

        foreach ($this->systemStore->getStoreValuesForForm(false, true) as $option) {
            if (is_array($option['value'] ?? null)) {
                foreach ($option['value'] as $storeOption) {
                    $options[] = $storeOption;
                }
                continue;
            }
            $options[] = $option;
        }

        return $options;
    }

    public function getScopeOptions(): array
    {
        return [
            'all' => 'Products, Categories, CMS Pages',
            'products' => 'Products',
            'categories' => 'Categories',
            'cms' => 'CMS Pages',
        ];
    }

    public function getCurrentScope(): string
    {
        $scope = (string) $this->getRequest()->getParam('scope', 'all');
        return isset($this->getScopeOptions()[$scope]) ? $scope : 'all';
    }

    public function getCurrentStoreId(): int
    {
        return max(0, (int) $this->getRequest()->getParam('store_id', 0));
    }

    public function getCurrentLimit(): int
    {
        return max(1, min(500, (int) $this->getRequest()->getParam('limit', 100)));
    }

    public function getCurrentOffset(): int
    {
        return max(0, (int) $this->getRequest()->getParam('offset', 0));
    }
}
