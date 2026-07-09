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

    public function getUsageMetadata(): array
    {
        $report = $this->getReport();
        $decoded = $report ? json_decode((string) $report->getData('usage_metadata'), true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    public function getUsageRows(): array
    {
        $usage = $this->getUsageMetadata();
        if (!$usage) {
            return [];
        }

        return [
            'Provider' => (string) ($usage['provider'] ?? '-'),
            'Model' => (string) ($usage['model'] ?? '-'),
            'Input Tokens' => (string) (int) ($usage['input_tokens'] ?? 0),
            'Output Tokens' => (string) (int) ($usage['output_tokens'] ?? 0),
            'Total Tokens' => (string) (int) ($usage['total_tokens'] ?? 0),
            'Estimated Cost' => $this->formatCost($usage),
        ];
    }

    public function getApprovalStatusLabel(string $status): string
    {
        return [
            ReportStatus::PENDING_APPROVAL => 'Pending Approval',
            ReportStatus::PROCESSING => 'Processing',
            ReportStatus::PARTIALLY_APPLIED => 'Partially Applied',
            ReportStatus::APPLIED => 'Applied',
            ReportStatus::FAILED => 'Failed',
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    public function getFieldLabel(string $code): string
    {
        return [
            'subtitle' => 'Subtitle',
            'features' => 'Features',
            'short_description' => 'Short Description',
            'description' => 'Description',
            'meta_title' => 'Meta Title',
            'meta_keyword' => 'Meta Keywords',
            'meta_description' => 'Meta Description',
            'image_label' => 'Base Image Label',
            'small_image_label' => 'Small Image Label',
            'thumbnail_label' => 'Thumbnail Label',
        ][$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    private function formatCost(array $usage): string
    {
        $cost = (float) ($usage['estimated_cost'] ?? 0);
        $currency = (string) ($usage['currency'] ?? 'USD');
        if ($cost <= 0) {
            return '-';
        }
        return $currency . ' ' . number_format($cost, 6);
    }

    private function getDataArray(): array
    {
        $report = $this->getReport();
        $decoded = $report ? json_decode((string) $report->getData('ai_data'), true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
