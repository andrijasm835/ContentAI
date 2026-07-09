<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\SeoReport;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::seo_report';

    private PageFactory $resultPageFactory;

    public function __construct(Action\Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Nistruct_ContentAI::seo_report_menu');
        $page->getConfig()->getTitle()->prepend(__('ContentAI SEO Reports'));
        return $page;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
