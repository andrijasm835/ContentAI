<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\BulkReport;

use Magento\Backend\App\Action;
use Nistruct\ContentAI\Model\Apply\ProductAttributeApplyService;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\BulkReportFactory;
use Nistruct\ContentAI\Model\ReportStatus;
use Psr\Log\LoggerInterface;

class Apply extends Action
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::bulk_report';

    private const LEGACY_FIELD_TARGETS = [
        'subtitle' => 'product_subtitle',
        'features' => 'tech_specs_features',
    ];

    private $reportFactory;
    private ProductAttributeApplyService $applyService;
    private ProductFieldProvider $fieldProvider;
    private $helper;
    private $logger;

    public function __construct(
        Action\Context $context,
        BulkReportFactory $reportFactory,
        ProductAttributeApplyService $applyService,
        ProductFieldProvider $fieldProvider,
        HelperData $helper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->reportFactory = $reportFactory;
        $this->applyService = $applyService;
        $this->fieldProvider = $fieldProvider;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $report = $this->reportFactory->create()->load($id);
        if (!$report->getId()) {
            $this->messageManager->addErrorMessage(__('Bulk report no longer exists.'));
            return $this->_redirect('*/*/index');
        }

        $data = json_decode((string) $report->getData('ai_data'), true);
        if (!is_array($data) || !is_array($data['products'] ?? null)) {
            $this->messageManager->addErrorMessage(__('Bulk report data is invalid.'));
            return $this->_redirect('*/*/view', ['id' => $id]);
        }

        $selected = (array) $this->getRequest()->getParam('apply_fields', []);
        $storeId = max(0, (int) $report->getData('store_id'));
        $applied = [];

        foreach ($selected as $index => $codes) {
            $index = (int) $index;
            if (empty($data['products'][$index]) || !is_array($codes)) {
                continue;
            }

            $productData = &$data['products'][$index];
            $sku = (string) ($productData['sku'] ?? '');
            $fields = is_array($productData['fields'] ?? null) ? $productData['fields'] : [];
            $save = $this->buildSaveData($codes, $fields);
            if ($sku === '' || !$save) {
                continue;
            }

            try {
                $result = $this->applyService->apply($sku, $save, $storeId);
                $productData['applied_fields'] = array_keys($result['saved']);
                $productData['skipped_fields'] = $result['skipped'];
                $productData['approval_status'] = ReportStatus::APPLIED;
                $applied[$sku] = array_keys($result['saved']);
            } catch (\Exception $e) {
                $this->logger->error('ContentAI bulk apply failed for ' . $sku . ': ' . $e->getMessage());
            }
        }

        $report->setAiData(json_encode($data, JSON_UNESCAPED_UNICODE));
        $report->setAppliedFields(json_encode($applied));
        $report->setAppliedAt(date('Y-m-d H:i:s'));
        $report->setApprovalStatus($this->getBatchStatus($data['products']));
        $report->save();

        $this->messageManager->addSuccessMessage(__('Selected generated fields were applied.'));
        return $this->_redirect('*/*/view', ['id' => $id]);
    }

    private function buildSaveData(array $codes, array $fields): array
    {
        $save = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (!isset($fields[$code]) || !is_scalar($fields[$code])) {
                continue;
            }
            $target = self::LEGACY_FIELD_TARGETS[$code] ?? $code;
            if (!$this->fieldProvider->getField($target)) {
                continue;
            }
            $save[$target] = $this->helper->sanitizeHtml((string) $fields[$code]);
        }
        return $save;
    }

    private function getBatchStatus(array $products): string
    {
        $hasApplied = false;
        $hasPending = false;
        foreach ($products as $product) {
            $status = (string) ($product['approval_status'] ?? ReportStatus::PENDING_APPROVAL);
            if ($status === ReportStatus::APPLIED) {
                $hasApplied = true;
            } elseif (!empty($product['fields'])) {
                $hasPending = true;
            }
        }

        if ($hasApplied && $hasPending) {
            return ReportStatus::PARTIALLY_APPLIED;
        }

        return $hasPending ? ReportStatus::PENDING_APPROVAL : ReportStatus::APPLIED;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
