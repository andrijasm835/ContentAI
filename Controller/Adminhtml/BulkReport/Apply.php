<?php
namespace Nistruct\ContentAI\Controller\Adminhtml\BulkReport;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Nistruct\ContentAI\Helper\Data as HelperData;
use Nistruct\ContentAI\Model\BulkReportFactory;
use Nistruct\ContentAI\Model\ReportStatus;
use Psr\Log\LoggerInterface;

class Apply extends Action
{
    public const ADMIN_RESOURCE = 'Nistruct_ContentAI::bulk_report';

    private const FIELD_TARGETS = [
        'subtitle' => 'product_subtitle',
        'features' => 'tech_specs_features',
        'short_description' => 'short_description',
        'description' => 'description',
        'meta_title' => 'meta_title',
        'meta_keyword' => 'meta_keyword',
        'meta_description' => 'meta_description',
        'image_label' => 'image_label',
        'small_image_label' => 'small_image_label',
        'thumbnail_label' => 'thumbnail_label',
    ];

    private $reportFactory;
    private $productRepository;
    private $productAction;
    private $helper;
    private $logger;

    public function __construct(
        Action\Context $context,
        BulkReportFactory $reportFactory,
        ProductRepositoryInterface $productRepository,
        ProductAction $productAction,
        HelperData $helper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->reportFactory = $reportFactory;
        $this->productRepository = $productRepository;
        $this->productAction = $productAction;
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
                $product = $this->productRepository->get($sku, false, $storeId, true);
                $this->productAction->updateAttributes([(int) $product->getId()], $save, $storeId);
                $productData['applied_fields'] = array_keys($save);
                $productData['approval_status'] = ReportStatus::APPLIED;
                $applied[$sku] = array_keys($save);
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
            $target = self::FIELD_TARGETS[$code] ?? $code;
            if (isset($fields[$code]) && is_scalar($fields[$code])) {
                $save[$target] = $this->helper->sanitizeHtml((string) $fields[$code]);
            }
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
