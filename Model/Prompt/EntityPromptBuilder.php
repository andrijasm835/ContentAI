<?php
namespace Nistruct\ContentAI\Model\Prompt;

use Nistruct\ContentAI\Model\Scope\GenerationContext;

class EntityPromptBuilder
{
    private const EXCLUDED_CONTEXT_FIELDS = [
        'entity_id', 'attribute_set_id', 'store_id', 'has_options', 'required_options', 'created_at',
        'updated_at', 'tier_price_changed', 'is_salable', 'image', 'small_image', 'thumbnail',
        'swatch_image', 'image_url', 'media_gallery', 'options_container', 'contentai_status',
        'contentai_last_generated_at',
    ];

    public function build(string $entityType, array $selectedFields, array $entityData, GenerationContext $context): string
    {
        $entityType = $entityType === 'category' ? 'category' : 'product';
        $lines = [
            'Generate improved Magento ' . $entityType . ' field values for the selected output fields.',
            'Target locale: ' . $context->getLocale() . '.',
            'Target language: ' . $context->getLanguage() . '.',
            'All generated values must follow the target locale and language.',
            'Use current data only as factual source material; write fresh improved content rather than copying its language or wording.',
            'Keep brand names, SKU values, model names, product codes and established product names unchanged.',
            'Return only a valid JSON object whose keys are exactly the selected output field codes.',
            'Do not return unselected fields or invent specifications, certifications, dimensions, prices, stock or unsupported claims.',
            'Use clean HTML only for fields explicitly intended for rich text. Use plain text for names, titles, meta fields and short scalar fields.',
        ];
        if ($context->getScript() !== '') {
            array_splice($lines, 3, 0, ['Target script: ' . $context->getScript() . '.']);
        }
        if ($context->getLanguageInstruction() !== '') {
            $lines[] = 'Additional language/style instruction: ' . $context->getLanguageInstruction();
        }
        $lines[] = '';
        $lines[] = 'Selected fields:';
        foreach ($selectedFields as $field) {
            $code = (string)($field['code'] ?? '');
            if ($code === '') { continue; }
            $format = !empty($field['allows_html']) ? 'clean HTML allowed' : 'plain text';
            $lines[] = sprintf('- %s (%s; %s), current value: %s', (string)($field['label'] ?? $code), $code, $format, $this->normalize((string)($field['value'] ?? '')));
        }
        $lines[] = '';
        $lines[] = 'Current ' . $entityType . ' data:';
        foreach ($entityData as $code => $field) {
            if (!is_array($field) || strpos((string)$code, '_') === 0 || in_array($code, self::EXCLUDED_CONTEXT_FIELDS, true) || strpos((string)$code, 'contentai_') === 0) { continue; }
            $value = (string)($field['value'] ?? '');
            if (!$this->isUseful($value)) { continue; }
            $lines[] = sprintf('- %s (%s): %s', (string)($field['label'] ?? $code), $code, $this->normalize($value));
        }
        $lines[] = '';
        $lines[] = 'Return format example: {"description":"Generated value","meta_title":"Generated title"}';
        return implode("\n", $lines);
    }

    private function normalize(string $value): string
    {
        $value = trim((string)preg_replace('/\s+/', ' ', strip_tags($value)));
        return strlen($value) > 1000 ? substr($value, 0, 1000) . '...' : $value;
    }

    private function isUseful(string $value): bool
    {
        $value = trim(strip_tags($value));
        return $value !== '' && $value !== '-' && strtolower($value) !== 'no_selection' && !(is_numeric($value) && (float)$value == 0.0);
    }
}
