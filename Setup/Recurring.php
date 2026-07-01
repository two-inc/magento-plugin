<?php
declare(strict_types=1);

/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Two\Gateway\Setup;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Fires on every `bin/magento setup:upgrade`. Ensures that runtime
 * caches survive a plugin DI change cleanly:
 *
 *   - config, layout, translate, full_page caches are flushed so
 *     they re-build against the freshly-compiled interceptor list.
 *     Without this, Magento's persistent cache backends (Redis,
 *     file) keep serving rendered output that pre-dates the plugin
 *     update, which is the cross-deployment-path manifestation of
 *     ABN-415's bug class.
 *
 *   - opcache_reset() is called best-effort. In CLI it only clears
 *     the CLI process's opcache (mostly cosmetic), but it does no
 *     harm. FPM-worker opcache is handled separately by the helm
 *     post-rotation hook on our k8s deployments; merchants on
 *     stock prod / Cloud / Docker should follow the install README
 *     guidance.
 */
class Recurring implements InstallSchemaInterface
{
    private TypeListInterface $cacheTypeList;

    public function __construct(TypeListInterface $cacheTypeList)
    {
        $this->cacheTypeList = $cacheTypeList;
    }

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        foreach (['config', 'layout', 'translate', 'full_page'] as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
