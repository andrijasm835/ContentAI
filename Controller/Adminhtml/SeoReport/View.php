<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\SeoReport;

use Magento\Backend\App\Action;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Nistruct\ContentAI\Model\SeoReportFactory;

class View extends Action
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::seo_report';

    private PageFactory $resultPageFactory;
    private Registry $registry;
    private SeoReportFactory $seoReportFactory;

    public function __construct(
        Action\Context $context,
        PageFactory $resultPageFactory,
        Registry $registry,
        SeoReportFactory $seoReportFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->registry = $registry;
        $this->seoReportFactory = $seoReportFactory;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $report = $this->seoReportFactory->create()->load($id);
        if (!$report->getId()) {
            $this->messageManager->addErrorMessage(__('SEO report no longer exists.'));
            return $this->_redirect('*/*/index');
        }

        $this->registry->register('current_contentai_seo_report', $report);
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Nistruct_ContentAI::seo_report_menu');
        $page->getConfig()->getTitle()->prepend(__('ContentAI SEO Report #%1', $report->getId()));
        return $page;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
