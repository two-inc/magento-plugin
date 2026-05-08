<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Skinny brand-override module for the ABN AMRO Zakelijk op Rekening
 * release. Ships only DI overrides, ABN-specific CSP hosts, ABN
 * translations, ABN brand assets, and ABN-specific CSS — no PHP
 * code of its own. All runtime logic lives in Two_Gateway.
 */
declare(strict_types=1);

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'ABN_Gateway',
    __DIR__
);
