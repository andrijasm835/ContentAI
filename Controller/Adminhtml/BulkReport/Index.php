<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\BulkReport;
use Magento\Backend\App\Action; use Magento\Framework\View\Result\PageFactory;
class Index extends Action { public const ADMIN_RESOURCE='Nistruct_ContentAI::bulk_report'; private $resultPageFactory; public function __construct(Action\Context $context, PageFactory $resultPageFactory){parent::__construct($context);$this->resultPageFactory=$resultPageFactory;} public function execute(){ $p=$this->resultPageFactory->create();$p->setActiveMenu('Nistruct_ContentAI::bulk_report_menu');$p->getConfig()->getTitle()->prepend(__('ContentAI Bulk Reports'));return $p;} protected function _isAllowed(){return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);} }
