# Magento Plugin — AI Agent Context

## Project Overview

Two's Magento 2 payment plugin, providing BNPL (Buy Now Pay Later) checkout integration for Magento stores.

-   **Language**: PHP 7.4+
-   **Framework**: Magento 2 module
-   **Purpose**: Payment gateway integration for Two BNPL service

## Directory Structure

```
etc/                  # Module configuration (module.xml, di.xml, etc.)
Model/                # Business logic and data models
Controller/           # Controllers for routes
Block/                # View layer blocks
view/                 # Frontend/adminhtml templates and layouts
Observer/             # Event observers
Plugin/               # Plugins (interceptors)
Setup/                # Installation/upgrade scripts
i18n/                 # Translations
```

## Development Notes

-   Follows Magento 2 module structure and conventions
-   Uses Magento dependency injection (prefer constructor injection)
-   Database schema managed via declarative schema (db_schema.xml)
-   Integrates with Magento checkout flow
-   Communicates with Two's payment API

## Testing

```bash
php bin/magento setup:upgrade       # Apply schema changes
php bin/magento cache:flush         # Clear cache
```

## Common Patterns

-   Use dependency injection via di.xml
-   Implement API interfaces for service contracts
-   Use plugins for extending/modifying behavior
-   Observers for event-driven logic
-   ACL resources for admin permissions
-   Repository pattern for data access
