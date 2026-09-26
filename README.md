# Magento 2 ContentAI

ContentAI generates and reviews Magento product and category content from the Admin. It supports single-entity and bulk workflows, approval reports, SEO checks, multiple websites and store views, and OpenAI or Anthropic.

## Requirements

- Magento 2.4.x
- PHP 8.1, 8.2 or 8.3
- An OpenAI or Anthropic API key

## Installation

Install the Composer package in the Magento project, then run:

```bash
bin/magento module:enable Nistruct_ContentAI
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The package version is derived from the Git tag or Composer repository metadata rather than a hardcoded `version` field.

## Configuration

Open **Stores > Configuration > Nistruct Extensions > ContentAI**.

- **Enable** controls all ContentAI Admin features.
- **Provider** selects OpenAI or Anthropic. Each provider accepts a configurable model ID, so newly released compatible models can be used without a ContentAI release. The shipped values are recommendations and can be replaced in configuration.
- **API Key**, **Model**, and **Max Response Length** configure the selected provider.
- **Default Content Locale** controls generation language for All Store Views (`store_id = 0`).
- **Additional Language Instruction** adds scope-specific terminology, script, or tone guidance.
- **Allowed Product Attributes** and **Allowed Category Attributes** are the only fields ContentAI may generate or update.
- **Debug Logging** permits full prompt and response diagnostics and should be enabled only temporarily.

Eligible custom `text` and `textarea` EAV attributes are discovered automatically, but must be selected in the appropriate allow-list before use. Product fields are also checked against each product's attribute set.

## Content Scopes

- **All Store Views** writes shared default EAV content at `store_id = 0` and uses Default Content Locale.
- **Specific Store View** writes a local override and uses that store view's Magento locale.
- **Global attributes** can only be generated from All Store Views.
- **Website-scoped attributes** are stored at the website's default storage store.
- **Store-view attributes** are stored only for the selected store view.

Inheritance-aware missing detection checks the value owned by the target storage scope. For example, if an English description exists at `store_id = 0` and a localized store inherits it, the localized content is still considered missing until that store has its own override.

## Multi-Website Behavior

Website selection determines the product population. The target store view determines the language and write scope. A SKU assigned to multiple websites is still one Magento product, and default-scope generation is de-duplicated for the shared entity and scope.

Category bulk generation is allowed only when selected websites share a compatible root category tree. Categories are never matched across websites by name.

## Providers and Resilience

OpenAI and Anthropic are implemented behind a provider interface. Temporary network failures, rate limits, and HTTP 5xx responses are retried a maximum of two additional times. Authentication, configuration, and invalid-request errors are not retried.

Administrator messages distinguish configuration/authentication errors, rate limits, provider availability, network failures, invalid responses, and empty generation results.

## Data and Privacy

Selected catalog information, prompts, and optional product images are sent to the configured third-party AI provider. Review that provider's privacy and data-retention terms before enabling the module.

API keys and authorization headers are never logged. Normal logging does not contain complete prompts or responses. Debug mode logs detailed prompts and provider responses, while base64 image content is redacted; use debug mode only in controlled environments.

## Troubleshooting

- **No fields visible:** select eligible fields in the ContentAI allow-list.
- **Custom attribute unavailable:** confirm it is a text/textarea attribute, is assigned to the product attribute set, and is allow-listed.
- **Store still shows inherited content:** apply generated content to the concrete store view to create an override.
- **Global field unavailable locally:** edit/generate it in All Store Views.
- **Category root mismatch:** select one website or websites sharing the same category root.
- **Authentication or rate-limit error:** verify credentials and provider account limits; temporary rate limits are retried automatically.

## Extension Architecture

- **Scope:** `GenerationContext`, `GenerationContextResolver`, and `LanguageResolver` own target/source scopes, locale, language, and script.
- **Fields:** `ProductFieldProvider`, `CategoryFieldProvider`, and `AttributeEligibility` own safe EAV discovery, allow-lists, and attribute-set availability.
- **EAV:** `AttributeStorageScopeResolver` and `ProductAttributeValueResolver` own storage IDs, inheritance, and missing-value semantics.
- **Generation:** `EntityPromptBuilder` creates provider-neutral prompts.
- **Apply:** `ProductAttributeApplyService` validates and writes generated product values.
- **Media:** `ImageLabelService` resolves role images and store-specific media labels.
- **Providers:** `AiProviderInterface`, `ProviderPool`, `OpenAiProvider`, and `AnthropicProvider` isolate external API formats.

Project-specific behavior should be implemented through configuration or separate extension classes, not in controllers.

## Reports and Compatibility

Generation reports retain generated content, scope context, status, timestamps, and available usage metadata. Existing report tables and stored reports remain compatible, including legacy field aliases handled by the report apply path.

## Deferred to a Future Major Iteration

Message queues, cron scheduling, asynchronous high-volume generation, automatic translation workflows, provider billing, customer-facing generation, prompt marketplaces, and per-client PHP rules are intentionally outside this release.
