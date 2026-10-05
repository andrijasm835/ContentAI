<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\Seo;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
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
        $filters = [
            'product_category_ids' => (array) $this->getRequest()->getParam('product_category_ids', []),
            'product_skus' => (string) $this->getRequest()->getParam('product_skus', ''),
            'product_status' => (string) $this->getRequest()->getParam('product_status', ''),
            'product_missing_fields' => (array) $this->getRequest()->getParam('product_missing_fields', []),
            'category_ids' => (array) $this->getRequest()->getParam('category_ids', []),
            'category_active' => (string) $this->getRequest()->getParam('category_active', ''),
            'category_missing_fields' => (array) $this->getRequest()->getParam('category_missing_fields', []),
            'cms_page_ids' => (array) $this->getRequest()->getParam('cms_page_ids', []),
            'cms_active' => (string) $this->getRequest()->getParam('cms_active', ''),
            'cms_missing_fields' => (array) $this->getRequest()->getParam('cms_missing_fields', []),
        ];

        try {
            $data = $this->analyzer->analyze($scope, $storeId, $limit, $offset, $filters);
            $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];

            $report = $this->seoReportFactory->create();
            $report->setData('scope', (string) ($summary['scope'] ?? $scope));
            $report->setData('store_id', (int) ($summary['store_id'] ?? $storeId));
            $report->setData('batch_limit', (int) ($summary['limit'] ?? $limit));
            $report->setData('batch_offset', (int) ($summary['offset'] ?? $offset));
            $report->setData('total_available', (int) ($summary['total_available'] ?? 0));
            $report->setData('has_next_batch', !empty($summary['has_next_batch']) ? 1 : 0);
            $report->setData('health_score', isset($summary['health_score']) ? (int) $summary['health_score'] : 0);
            $report->setData('ai_fixable_items', (int) ($summary['ai_fixable_items'] ?? 0));
            $report->setData('total_entities', (int) ($summary['total_entities'] ?? 0));
            $report->setData('total_issues', (int) ($summary['total_issues'] ?? 0));
            $report->setData('critical_count', (int) ($summary['critical_count'] ?? 0));
            $report->setData('warning_count', (int) ($summary['warning_count'] ?? 0));
            $report->setData('notice_count', (int) ($summary['notice_count'] ?? 0));
            $report->setData('report_data', json_encode($data, JSON_UNESCAPED_UNICODE));
            $report->setData('created_at', date('Y-m-d H:i:s'));
            $report->save();

            if (($summary['status'] ?? '') === 'no_matches') {
                $this->messageManager->addNoticeMessage(__('SEO audit report #%1 created. No items matched the selected Store View and filters.', $report->getId()));
            } else {
                $this->messageManager->addSuccessMessage(__(
                    'SEO audit report #%1 created. Found %2 issue(s) across %3 scanned item(s).',
                    $report->getId(),
                    (int) $report->getData('total_issues'),
                    (int) $report->getData('total_entities')
                ));
            }

            return $this->_redirect('nistruct_contentai/seoreport/view', ['id' => $report->getId()]);
        } catch (LocalizedException $e) {
            $this->logger->warning('ContentAI SEO audit validation failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->_redirect('*/*/index');
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
