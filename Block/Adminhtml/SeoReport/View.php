<?php
namespace Nistruct\ContentAI\Block\Adminhtml\SeoReport;

use Magento\Backend\Block\Template;
use Magento\Framework\Registry;
use Nistruct\ContentAI\Model\SeoReport;

class View extends Template
{
    private Registry $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    public function getReport(): ?SeoReport
    {
        $report = $this->registry->registry('current_contentai_seo_report');
        return $report instanceof SeoReport ? $report : null;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seoreport/index');
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('nistruct_contentai/seo/index');
    }

    public function getNextBatchUrl(): string
    {
        $summary = $this->getSummary();
        if (empty($summary['has_next_batch'])) {
            return '';
        }

        return $this->getUrl('nistruct_contentai/seo/index', [
            'scope' => (string) ($summary['scope'] ?? 'all'),
            'store_id' => (int) ($summary['store_id'] ?? 0),
            'limit' => (int) ($summary['limit'] ?? 100),
            'offset' => (int) ($summary['next_offset'] ?? 0),
        ]);
    }

    public function getReportData(): array
    {
        $report = $this->getReport();
        $decoded = $report ? json_decode((string) $report->getData('report_data'), true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    public function getSummary(): array
    {
        $data = $this->getReportData();
        return is_array($data['summary'] ?? null) ? $data['summary'] : [];
    }

    public function getSections(): array
    {
        $data = $this->getReportData();
        return is_array($data['sections'] ?? null) ? $data['sections'] : [];
    }

    public function getScopeLabel(string $scope): string
    {
        return [
            'all' => 'Products, Categories, CMS Pages',
            'products' => 'Products',
            'categories' => 'Categories',
            'cms' => 'CMS Pages',
        ][$scope] ?? ucwords(str_replace('_', ' ', $scope));
    }

    public function getSeverityLabel(string $severity): string
    {
        return [
            'critical' => 'Critical',
            'warning' => 'Warning',
            'notice' => 'Notice',
        ][$severity] ?? ucwords($severity);
    }
}
