<?php
namespace Nistruct\ContentAI\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Nistruct\ContentAI\Model\Field\ProductFieldProvider;
use Nistruct\ContentAI\Model\Field\CategoryFieldProvider;
use Magento\Catalog\Api\ProductRepositoryInterface;

class Generate extends Template
{
    private ProductFieldProvider $fieldProvider;
    private CategoryFieldProvider $categoryFieldProvider;
    private ProductRepositoryInterface $productRepository;

    public function __construct(Template\Context $context, ProductFieldProvider $fieldProvider, CategoryFieldProvider $categoryFieldProvider, ProductRepositoryInterface $productRepository, array $data = [])
    {
        parent::__construct($context, $data);
        $this->fieldProvider = $fieldProvider;
        $this->categoryFieldProvider = $categoryFieldProvider;
        $this->productRepository = $productRepository;
    }

    public function getProductFieldsJson(): string
    {
        $fields=$this->fieldProvider->getFields();
        $productId=(int)$this->getRequest()->getParam('id');
        if ($productId) {
            try { $fields=$this->fieldProvider->getFieldsForAttributeSet((int)$this->productRepository->getById($productId)->getAttributeSetId()); } catch (\Exception $e) {}
        }
        return (string)json_encode(array_values($fields), JSON_UNESCAPED_UNICODE);
    }

    public function getCategoryFieldsJson(): string
    {
        return (string)json_encode(array_values($this->categoryFieldProvider->getFields()), JSON_UNESCAPED_UNICODE);
    }
}
