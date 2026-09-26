<?php
namespace Nistruct\ContentAI\Block\Adminhtml\Report;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Registry;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Report;

class View extends Template
{
    private const PRODUCT_FIELD_ORDER = [
        'name',
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
    private EavConfig $eavConfig;

    public function __construct(
        Context $context,
        Registry $registry,
        ProductRepositoryInterface $productRepository,
        EavConfig $eavConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->productRepository = $productRepository;
        $this->eavConfig = $eavConfig;
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

        return $this->getUrl('catalog/category/edit', [
            'id' => (int)$report->getData('category_id'),
            'store' => max(0, (int)$report->getData('store_id')),
        ]);
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
        $fieldOrder = $this->getEntityTypeLabel() === 'Category'
            ? self::CATEGORY_FIELD_ORDER
            : self::PRODUCT_FIELD_ORDER;
        foreach ($fieldOrder as $code) {
            if (array_key_exists($code, $fields)) {
                $rows[$code] = $fields[$code];
            }
        }

        $remaining = array_diff_key($fields, $rows);
        uksort($remaining, function (string $left, string $right): int {
            return strnatcasecmp($this->getFieldLabel($left), $this->getFieldLabel($right));
        });
        foreach ($remaining as $code => $value) {
            $rows[$code] = $value;
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

    public function getFieldLabel(string $code): string
    {
        $fallbackLabel = ucwords(str_replace('_', ' ', $code));
        if (isset(ProductFieldProvider::PSEUDO_FIELDS[$code])) {
            return ProductFieldProvider::PSEUDO_FIELDS[$code];
        }

        $legacyLabels = [
            'subtitle' => 'Subtitle',
            'features' => 'Features',
        ];
        if (isset($legacyLabels[$code])) {
            return $legacyLabels[$code];
        }

        try {
            $entityType = $this->getEntityTypeLabel() === 'Category' ? 'catalog_category' : 'catalog_product';
            $attribute = $this->eavConfig->getAttribute($entityType, $code);
            if ($attribute && (int)$attribute->getAttributeId()) {
                $label = trim((string)$attribute->getDefaultFrontendLabel());
                if ($label !== '') {
                    return $label;
                }
            }
        } catch (\Exception $e) {
            return $fallbackLabel;
        }

        return $fallbackLabel;
    }

    private function normalizeFieldCode(string $code, string $entityType): string
    {
        if ($entityType === 'category') {
            return ['meta_keyword' => 'meta_keywords', 'keywords' => 'meta_keywords'][$code] ?? $code;
        }

        return ['meta_keywords' => 'meta_keyword', 'keywords' => 'meta_keyword'][$code] ?? $code;
    }
}
