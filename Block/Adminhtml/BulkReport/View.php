<?php
namespace Nistruct\ContentAI\Block\Adminhtml\BulkReport;

use Magento\Backend\Block\Template;
use Magento\Framework\Registry;
use Nistruct\ContentAI\Model\BulkReport;
use Nistruct\ContentAI\Model\ReportStatus;

class View extends Template
{
    private $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    public function getReport(): ?BulkReport
    {
        $report = $this->registry->registry('current_contentai_bulk_report');
        return $report instanceof BulkReport ? $report : null;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('nistruct_contentai/bulkreport/index');
    }

    public function getApplyUrl(): string
    {
        $report = $this->getReport();
        return $this->getUrl('nistruct_contentai/bulkreport/apply', ['id' => $report ? $report->getId() : 0]);
    }

    public function getProducts(): array
    {
        $data = $this->getDataArray();
        return is_array($data['products'] ?? null) ? $data['products'] : [];
    }

    public function getGeneratedFields(array $product): array
    {
        return is_array($product['fields'] ?? null) ? $product['fields'] : [];
    }

    public function canApplyProduct(array $product): bool
    {
        return !empty($product['fields']) && ($product['approval_status'] ?? '') !== ReportStatus::APPLIED;
    }

    public function getProductCount(): int
    {
        return count($this->getProducts());
    }

    public function getPendingCount(): int
    {
        return count(array_filter($this->getProducts(), function ($product) {
            return $this->canApplyProduct($product);
        }));
    }

    public function getApprovalStatusLabel(string $status): string
    {
        return [
            ReportStatus::PENDING_APPROVAL => 'Ready for Review',
            ReportStatus::PROCESSING => 'Generating',
            ReportStatus::PARTIALLY_APPLIED => 'Partially Saved',
            ReportStatus::APPLIED => 'Saved to Catalog',
            ReportStatus::FAILED => 'Failed',
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public function getFieldLabel(string $code): string
    {
        return [
            'subtitle' => 'Subtitle',
            'features' => 'Features',
            'short_description' => 'Short Product Description',
            'description' => 'Description',
            'meta_title' => 'SEO Page Title',
            'meta_keyword' => 'SEO Keywords',
            'meta_description' => 'SEO Search Description',
            'image_label' => 'Main Image Alt Text',
            'small_image_label' => 'Small Image Alt Text',
            'thumbnail_label' => 'Thumbnail Alt Text',
        ][$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    private function getDataArray(): array
    {
        $report = $this->getReport();
        $decoded = $report ? json_decode((string) $report->getData('ai_data'), true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
