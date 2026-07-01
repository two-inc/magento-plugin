<?php
/**
 * ComponentRegistrar stub with the getPaths surface intact — required
 * before the catch-all autoloader so Loader tests can mock module
 * enumeration (the catch-all would stub an empty class without the
 * method, and onlyMethods() refuses methods that don't exist).
 *
 * Must be required BEFORE the catch-all autoloader in bootstrap.php.
 */
declare(strict_types=1);

namespace Magento\Framework\Component {
    if (!class_exists(ComponentRegistrar::class, false)) {
        class ComponentRegistrar
        {
            public const MODULE = 'module';

            public function getPaths($type)
            {
                return [];
            }
        }
    }
}
