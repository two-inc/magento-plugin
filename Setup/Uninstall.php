<?php
declare(strict_types=1);

/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Two\Gateway\Setup;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\UninstallInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Fires on `bin/magento module:uninstall Two_Gateway` (TWO-25386).
 * Uninstall is the nearest lifecycle event Magento offers for this: there
 * is no admin-triggered "disable this module" hook a payment module can act
 * on.
 *
 * Deletes stored `payment/<code>/*` configuration from core_config_data
 * ONLY when the merchant opted in via the "Clear settings on module
 * uninstall" toggle. Default off, so uninstall leaves configuration in
 * place unless the merchant asked otherwise.
 *
 * `<code>` is resolved from BrandRegistryInterface::getCode() rather than
 * hardcoded to `two_payment` — every field this module ships writes to
 * `payment/<code>/*` (see Model\Config\Repository::path()), and a brand
 * overlay's active code is not `two_payment` (see
 * docs/brand-overlay-guide.md, and etc/adminhtml/brand_form_template.xml's
 * `{{code}}` tokens). There is deliberately no separate `two_search/*`
 * prefix to clean up: the fields grouped under the admin "Search" section
 * (`enable_company_search`, `enable_address_search`) are, like everything
 * else, stored under `payment/<code>/*` — `two_search` is only an
 * admin-UI section id, never a config path.
 */
class Uninstall implements UninstallInterface
{
    private ScopeConfigInterface $scopeConfig;
    private BrandRegistryInterface $brandRegistry;

    public function __construct(ScopeConfigInterface $scopeConfig, BrandRegistryInterface $brandRegistry)
    {
        $this->scopeConfig = $scopeConfig;
        $this->brandRegistry = $brandRegistry;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $code = $this->brandRegistry->getCode();

        // Default scope, explicitly: the toggle is showInWebsite="0"
        // showInStore="0" (see system.xml) — it can only ever be set at
        // default scope, so reading it there is what the field's own
        // declaration means, not an assumption.
        if (!$this->scopeConfig->isSetFlag(
            'payment/' . $code . '/clear_settings_on_uninstall',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        )) {
            return;
        }

        $setup->startSetup();
        $connection = $setup->getConnection();
        $table = $setup->getTable('core_config_data');
        // LIKE special characters ('_', '%') in the brand code (e.g. the
        // literal underscore in "two_payment") must be escaped, or they
        // widen the match to unrelated config paths rather than narrowing
        // it to this brand's own rows.
        $pattern = $this->escapeLike('payment/' . $code . '/') . '%';
        $connection->delete($table, [
            $connection->quoteInto("path LIKE ? ESCAPE '\\\\'", $pattern),
        ]);
        $setup->endSetup();
    }

    /**
     * Escapes '\', '_' and '%' for use inside a LIKE pattern with
     * `ESCAPE '\\'`, so the literal segment of the pattern only ever
     * matches itself.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $value);
    }
}
