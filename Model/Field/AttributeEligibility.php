<?php

namespace Nistruct\ContentAI\Model\Field;

class AttributeEligibility
{
    private const PRODUCT_EXCLUDED = [
        'sku', 'price', 'cost', 'special_price', 'status', 'visibility', 'tax_class_id', 'url_key',
        'created_at', 'updated_at', 'quantity_and_stock_status', 'media_gallery', 'image', 'small_image',
        'thumbnail', 'swatch_image', 'options_container', 'gift_message_available', 'custom_design',
        'custom_layout', 'page_layout', 'msrp', 'msrp_display_actual_price_type',
    ];
    private const CATEGORY_EXCLUDED = [
        'url_key', 'url_path', 'path', 'children', 'image', 'is_active', 'include_in_menu', 'display_mode',
        'landing_page', 'custom_design', 'custom_layout_update', 'page_layout',
    ];
    public function isEligible(string $entityType, $attribute): bool
    {
        $excluded = $entityType === 'catalog_category' ? self::CATEGORY_EXCLUDED : self::PRODUCT_EXCLUDED;
        return !in_array((string)$attribute->getAttributeCode(), $excluded, true)
            && in_array((string)$attribute->getFrontendInput(), ['text', 'textarea'], true)
            && in_array((string)$attribute->getBackendType(), ['varchar', 'text'], true);
    }
}
