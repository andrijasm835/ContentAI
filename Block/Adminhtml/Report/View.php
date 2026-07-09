<?php
namespace Nistruct\ContentAI\Block\Adminhtml\Report;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Registry;
use Nistruct\ContentAI\Model\Report;

class View extends Template
{
    private const PRODUCT_FIELD_ORDER = [
        'subtitle',
        'features',
        'short_description',
        'description',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'image_label',
        'small_image_label',
        'thumbnail_label',
    ];

    private const CATEGORY_FIELD_ORDER = [
        'description',
        'meta_title',
        'meta_keywords',
        'meta_description',
    ];

    private $registry;
    private $productRepository;

    public function __construct(
        Context $context,
        Registry $registry,
        ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->productRepository = $productRepository;
    }

    public function getReport(): ?Report
    {
        $report = $this->registry->registry('current_contentai_report');
        return $report instanceof Report ? $report : null;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('nistruct_contentai/report/index');
    }

    public function getProductEditUrl(): string
    {
        $report = $this->getReport();
        if (!$report || !$report->getData('product_sku')) {
            return '';
        }

        try {
            $product = $this->productRepository->get((string) $report->getData('product_sku'));
            return $this->getUrl('catalog/product/edit', ['id' => $product->getId()]);
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getCategoryEditUrl(): string
    {
        $report = $this->getReport();
        if (!$report || !$report->getData('category_id')) {
            return '';
        }

        return $this->getUrl('catalog/category/edit', ['id' => (int) $report->getData('category_id')]);
    }

    public function getEntityTypeLabel(): string
    {
        $report = $this->getReport();
        $type = $report ? (string) $report->getData('entity_type') : 'product';

        return $type === 'category' ? 'Category' : 'Product';
    }

    public function getEntityIdentifierLabel(): string
    {
        return $this->getEntityTypeLabel() === 'Category' ? 'Category' : 'Product SKU';
    }

    public function getEntityIdentifierValue(): string
    {
        $report = $this->getReport();
        if (!$report) {
            return '-';
        }

        if ((string) $report->getData('entity_type') === 'category') {
            $name = (string) $report->getData('category_name');
            $id = (int) $report->getData('category_id');
            return trim($name . ($id ? ' (ID: ' . $id . ')' : '')) ?: '-';
        }

        return (string) $report->getData('product_sku') ?: '-';
    }

    public function isFieldReport(): bool
    {
        return is_array(json_decode((string) $this->getRawContent(), true));
    }

    public function getExpectedFieldRows(): array
    {
        $fields = $this->getDecodedFields();
        $rows = [];
        $fieldOrder = $this->getEntityTypeLabel() === 'Category' ? self::CATEGORY_FIELD_ORDER : self::PRODUCT_FIELD_ORDER;
        foreach ($fieldOrder as $code) {
            $rows[$code] = $fields[$code] ?? '';
        }
        foreach ($fields as $code => $value) {
            if (!isset($rows[$code])) {
                $rows[$code] = $value;
            }
        }
        return $rows;
    }

    public function getRawContent(): string
    {
        $report = $this->getReport();
        if (!$report) {
            return '';
        }

        return (string) ($report->getData('generated_content') ?: $report->getData('ai_description'));
    }

    public function getDecodedFields(): array
    {
        $decoded = json_decode($this->getRawContent(), true);
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        $entityType = $this->getEntityTypeLabel() === 'Category' ? 'category' : 'product';
        foreach ($decoded as $code => $value) {
            if (is_scalar($value)) {
                $fields[$this->normalizeFieldCode((string) $code, $entityType)] = (string) $value;
            }
        }
        return $fields;
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

    public function getFieldLabel(string $code): string
    {
        return [
            'subtitle' => 'Subtitle',
            'features' => 'Features',
            'short_description' => 'Short Description',
            'description' => 'Description',
            'meta_title' => 'Meta Title',
            'meta_keyword' => 'Meta Keywords',
            'meta_keywords' => 'Meta Keywords',
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

    private function normalizeFieldCode(string $code, string $entityType): string
    {
        if ($entityType === 'category') {
            return ['meta_keyword' => 'meta_keywords', 'keywords' => 'meta_keywords'][$code] ?? $code;
        }

        return ['meta_keywords' => 'meta_keyword', 'keywords' => 'meta_keyword'][$code] ?? $code;
    }
}
