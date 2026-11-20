<?php
/**
 * Magento's interception surface, with the real constants and method
 * signatures, so the order postprocessing subscriber detection (TWO-26276)
 * can be driven by a plugin list double. The catch-all autoloader's
 * method-less interfaces carry neither.
 */
declare(strict_types=1);

namespace Magento\Framework\Interception;

if (!interface_exists(DefinitionInterface::class, false)) {
    interface DefinitionInterface
    {
        public const LISTENER_BEFORE = 1;

        public const LISTENER_AROUND = 2;

        public const LISTENER_AFTER = 4;

        public function getMethodList($type);
    }
}

if (!interface_exists(PluginListInterface::class, false)) {
    interface PluginListInterface
    {
        public function getNext($type, $method, $code = null);

        public function getPlugin($type, $code);
    }
}

if (!interface_exists(InterceptorInterface::class, false)) {
    interface InterceptorInterface
    {
    }
}
