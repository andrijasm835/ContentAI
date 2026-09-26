<?php

namespace Nistruct\ContentAI\Model\Field;

use Magento\Framework\Exception\LocalizedException;

class ProductFieldRequestValidator
{
    private ProductFieldProvider $fieldProvider;

    public function __construct(ProductFieldProvider $fieldProvider)
    {
        $this->fieldProvider = $fieldProvider;
    }

    public function validateCodes(array $codes): array
    {
        $requested = array_values(array_unique(array_filter(array_map('strval', $codes))));
        $allowed = $this->fieldProvider->filterCodes($requested);
        $rejected = array_values(array_diff($requested, $allowed));
        if ($rejected) {
            throw new LocalizedException(
                __('The following product fields are not allowed: %1.', implode(', ', $rejected))
            );
        }

        return $allowed;
    }

    public function validateSelections(array $fields): array
    {
        $codes = [];
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['code'])) {
                $codes[] = (string)$field['code'];
            }
        }
        $this->validateCodes($codes);

        return $fields;
    }
}
