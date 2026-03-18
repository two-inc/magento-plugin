<?php
/**
 * PHPUnit bootstrap — provides a PSR-4 autoloader for the plugin
 * namespace and minimal stubs for Magento classes that are referenced
 * by the plugin but unavailable outside the full Magento framework.
 *
 * No vendor/autoload.php or composer install required.
 */
declare(strict_types=1);

// PSR-4 autoloader for Two\Gateway\ → project root
spl_autoload_register(function ($class) {
    $prefix = 'Two\\Gateway\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Explicit stubs — classes that need constants or methods
if (!class_exists(\Magento\Framework\Phrase::class)) {
    require_once __DIR__ . '/Stubs/Phrase.php';
}
if (!class_exists(\Magento\Framework\App\State::class)) {
    require_once __DIR__ . '/Stubs/State.php';
}
if (!interface_exists(\Magento\Store\Model\ScopeInterface::class)) {
    require_once __DIR__ . '/Stubs/ScopeInterface.php';
}
if (!interface_exists(\Magento\Framework\App\Config\ScopeConfigInterface::class)) {
    require_once __DIR__ . '/Stubs/ScopeConfigInterface.php';
}

// Catch-all autoloader for remaining Magento classes/interfaces.
// Creates empty stubs so that type hints, extends, and implements resolve.
spl_autoload_register(function ($class) {
    if (strncmp('Magento\\', $class, 8) !== 0) {
        return;
    }
    $parts = explode('\\', $class);
    $shortName = end($parts);
    $namespace = implode('\\', array_slice($parts, 0, -1));

    if (substr($shortName, -9) === 'Interface') {
        eval("namespace $namespace; interface $shortName {}");
    } else {
        eval("namespace $namespace; class $shortName {}");
    }
});

// Stub the __() translation helper
if (!function_exists('__')) {
    function __()
    {
        $args = func_get_args();
        $text = array_shift($args);
        return new \Magento\Framework\Phrase($text, $args);
    }
}
