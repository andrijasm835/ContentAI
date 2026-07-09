<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Seo;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Nistruct\ContentAI\Model\Seo\Analyzer;
use Nistruct\ContentAI\Model\SeoReportFactory;
use Psr\Log\LoggerInterface;

class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::seo';

    private Analyzer $analyzer;
    private SeoReportFactory $seoReportFactory;
    private LoggerInterface $logger;

    public function __construct(
        Action\Context $context,
        Analyzer $analyzer,
        SeoReportFactory $seoReportFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->analyzer = $analyzer;
        $this->seoReportFactory = $seoReportFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        $scope = (string) $this->getRequest()->getParam('scope', 'all');
        $storeId = max(0, (int) $this->getRequest()->getParam('store_id', 0));
        $limit = max(1, min(500, (int) $this->getRequest()->getParam('limit', 100)));
        $offset = max(0, (int) $this->getRequest()->getParam('offset', 0));

        try {
            $data = $this->analyzer->analyze($scope, $storeId, $limit, $offset);
            $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];

            $report = $this->seoReportFactory->create();
            $report->setData('scope', (string) ($summary['scope'] ?? $scope));
            $report->setData('store_id', (int) ($summary['store_id'] ?? $storeId));
            $report->setData('batch_limit', (int) ($summary['limit'] ?? $limit));
            $report->setData('batch_offset', (int) ($summary['offset'] ?? $offset));
            $report->setData('total_available', (int) ($summary['total_available'] ?? 0));
            $report->setData('has_next_batch', !empty($summary['has_next_batch']) ? 1 : 0);
            $report->setData('total_entities', (int) ($summary['total_entities'] ?? 0));
            $report->setData('total_issues', (int) ($summary['total_issues'] ?? 0));
            $report->setData('critical_count', (int) ($summary['critical_count'] ?? 0));
            $report->setData('warning_count', (int) ($summary['warning_count'] ?? 0));
            $report->setData('notice_count', (int) ($summary['notice_count'] ?? 0));
            $report->setData('report_data', json_encode($data, JSON_UNESCAPED_UNICODE));
            $report->setData('created_at', date('Y-m-d H:i:s'));
            $report->save();

            $this->messageManager->addSuccessMessage(__(
                'SEO audit report #%1 created. Found %2 issue(s) across %3 scanned item(s).',
                $report->getId(),
                (int) $report->getData('total_issues'),
                (int) $report->getData('total_entities')
            ));

            return $this->_redirect('nistruct_contentai/seoreport/view', ['id' => $report->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('ContentAI SEO audit failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('SEO audit failed. Check contentai.log or exception.log.'));
            return $this->_redirect('*/*/index');
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
