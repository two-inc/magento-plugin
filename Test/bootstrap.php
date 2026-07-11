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

if (!class_exists(\Magento\Framework\DataObject::class)) {
    require_once __DIR__ . '/Stubs/DataObject.php';
}
if (!class_exists(\Magento\Tax\Model\Calculation::class)) {
    require_once __DIR__ . '/Stubs/TaxCalculationInterface.php';
}
if (!interface_exists(\Magento\Framework\App\Config\Storage\WriterInterface::class)) {
    require_once __DIR__ . '/Stubs/WriterInterface.php';
}
if (!class_exists(\Magento\Framework\HTTP\Client\Curl::class)) {
    require_once __DIR__ . '/Stubs/Curl.php';
    require_once __DIR__ . '/Stubs/ComponentRegistrar.php';
}
if (!class_exists(\Magento\Framework\HTTP\Client\CurlFactory::class)) {
    require_once __DIR__ . '/Stubs/CurlFactory.php';
}
if (!class_exists(\Magento\Framework\Exception\LocalizedException::class)) {
    require_once __DIR__ . '/Stubs/LocalizedException.php';
}
if (!class_exists(\Magento\Bundle\Model\Product\Price::class)) {
    require_once __DIR__ . '/Stubs/BundlePrice.php';
}
if (!class_exists(\Magento\Catalog\Model\Product\Type::class)) {
    require_once __DIR__ . '/Stubs/ProductType.php';
}
// Sales models (Invoice, Creditmemo, Order) with faithful DataObject
// semantics — required before the catch-all below so the surcharge Total
// collectors operate on real data bags, not empty method-less stubs.
if (!class_exists(\Magento\Sales\Model\Order\Invoice::class, false)) {
    require_once __DIR__ . '/Stubs/SalesModels.php';
}
// Quote model with the CartInterface relationship intact - required so
// type hints against CartInterface accept Quote mocks.
if (!class_exists(\Magento\Quote\Model\Quote::class, false)) {
    require_once __DIR__ . '/Stubs/QuoteModels.php';
}
// Cache interface with real method signatures (mock targets) and a
// faithful Json serializer (instantiated directly, not mocked) for
// MinimumOrderProvider tests.
if (!interface_exists(\Magento\Framework\App\CacheInterface::class, false)) {
    require_once __DIR__ . '/Stubs/CacheInterface.php';
}
if (!class_exists(\Magento\Framework\Serialize\Serializer\Json::class, false)) {
    require_once __DIR__ . '/Stubs/JsonSerializer.php';
}
// Tax rules engine API surface (TaxCalculationInterface, QuoteDetails*
// data objects and their factories) with real signatures — required so
// SurchargeTaxCalculator tests get functioning factories/data bags
// instead of empty catch-all stubs. NoSuchEntityException must extend
// LocalizedException/\Exception to be throwable, so this loads after
// the LocalizedException stub above.
if (!interface_exists(\Magento\Tax\Api\TaxCalculationInterface::class, false)) {
    require_once __DIR__ . '/Stubs/LocalizedException.php';
    require_once __DIR__ . '/Stubs/TaxEngine.php';
}
// Quote total-collection surface (AbstractTotal with typed collect()
// signature, Total data bag, checkout Session magic bag) — required so
// Model\Total\Surcharge can be instantiated and collected in tests.
if (!class_exists(\Magento\Quote\Model\Quote\Address\Total::class, false)) {
    require_once __DIR__ . '/Stubs/QuoteTotals.php';
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
