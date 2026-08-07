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

/**
 * Fires on `bin/magento module:uninstall Two_Gateway` (TWO-25386, ported
 * from woocommerce-plugin's "clear settings on deactivation" — Magento's
 * nearest equivalent lifecycle event is uninstall, not deactivation:
 * Magento has no admin-triggered "disable this module" hook a payment
 * module can act on the way a WordPress plugin can).
 *
 * Deletes stored two_payment/two_search configuration from
 * core_config_data ONLY when the merchant opted in via the
 * "Clear settings on module uninstall" toggle. Default off, so uninstall
 * leaves configuration in place unless the merchant asked otherwise —
 * matching the WooCommerce default.
 */
class Uninstall implements UninstallInterface
{
    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        if (!$this->scopeConfig->isSetFlag('payment/two_payment/clear_settings_on_uninstall')) {
            return;
        }

        $setup->startSetup();
        $connection = $setup->getConnection();
        $table = $setup->getTable('core_config_data');
        foreach (['payment/two_payment/%', 'payment/two_search/%'] as $pathLike) {
            $connection->delete($table, ['path LIKE ?' => $pathLike]);
        }
        $setup->endSetup();
    }
}
