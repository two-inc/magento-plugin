<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * Stubs with real method signatures for the handful of Magento/PSR
 * collaborators that `SynthesiseBrandAdminFormProviderTokenTest` needs to
 * mock. Test/bootstrap.php's catch-all autoloader would otherwise create
 * empty classes for these (fine for the existing tests, which only
 * Reflect on SynthesiseBrandAdminForm's constructor) — but this test
 * calls `->method(...)` on mocks of these classes, and PHPUnit can only
 * stub a method that the mocked class actually declares.
 *
 * Loaded from Test/bootstrap.php guarded by `class_exists(..., false)` so
 * it never runs against a real Magento install.
 */

namespace Magento\Config\Model\Config\Structure {
    if (!class_exists(Converter::class, false)) {
        class Converter
        {
            /**
             * @param \DOMDocument $config
             * @return array<string,mixed>
             */
            public function convert($config): array
            {
                return [];
            }
        }
    }
}

namespace Magento\Framework\Module {
    if (!class_exists(Dir::class, false)) {
        class Dir
        {
            public const MODULE_ETC_DIR = 'etc';

            public function getDir(string $moduleName, string $type = self::MODULE_ETC_DIR): string
            {
                return '';
            }
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class, false)) {
        interface LoggerInterface
        {
            public function emergency($message, array $context = []);
            public function alert($message, array $context = []);
            public function critical($message, array $context = []);
            public function error($message, array $context = []);
            public function warning($message, array $context = []);
            public function notice($message, array $context = []);
            public function info($message, array $context = []);
            public function debug($message, array $context = []);
            public function log($level, $message, array $context = []);
        }
    }
}
