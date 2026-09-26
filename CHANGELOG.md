# Changelog

## 1.1.0

- Added multi-website and multi-store content generation.
- Added explicit support for the shared `store_id = 0` default content scope.
- Added inheritance-aware missing-local-content detection.
- Added global, website and store-view attribute storage handling.
- Added dynamic EAV field discovery and configurable field allow-lists.
- Added product attribute-set validation for generation and apply operations.
- Added locale, language and script-aware generation context.
- Added store-aware product image label generation and apply support.
- Added OpenAI and Anthropic provider implementations with bounded transient retries.
- Made provider model IDs configurable and removed retired hardcoded model choices.
- Added strict product-field request validation and critical scope, compatibility, and response-parser regression coverage.
- Preserved compatibility with legacy stored generation reports.
