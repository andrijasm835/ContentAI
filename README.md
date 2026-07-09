# Magento 2 ContentAI

An AI-powered Magento 2 module for generating product and category content, performing SEO analysis, and managing AI generation reports.

## Features

- AI-powered content generation for products and categories
- Bulk content generation
- SEO analysis and recommendations
- Generation reports with token usage and estimated costs
- Support for multiple AI providers
- OpenAI integration
- Anthropic Claude integration
- Magento Admin UI integration
- Configurable AI models and generation settings
- Debug logging

## Requirements

- Magento 2.4.x
- PHP 8.1+
- OpenAI API key or Anthropic API key

## Installation

```bash
bin/magento module:enable Nistruct_ContentAI
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

After installation, configure the module from the Magento Admin panel by selecting your AI provider, API credentials, model, and generation settings.

## Main Features

### Content Generation

Generate AI-powered descriptions for products and categories directly from the Magento Admin panel.

### Bulk Generation

Generate content for multiple entities in a single operation and track progress through dedicated reports.

### SEO Analysis

Analyze existing content and receive AI-powered SEO recommendations before generating improvements.

### Reports

The module stores generation history, including:

- Generated content
- Prompt and response
- Token usage
- Estimated API cost
- Generation status
- Creation date

## Supported AI Providers

- OpenAI
- Anthropic Claude

## Logging

Optional debug logging is available for troubleshooting API requests and responses.
