<?php
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Http;

use Magento\Framework\HTTP\Client\CurlFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Service\Api\Adapter;

/**
 * E2E-side Adapter constructor. Brand/translator/factory/PSR-17 mocks are
 * identical across every E2E test; only ConfigRepository + LogRepository vary.
 */
trait RealAdapterFactoryTrait
{
    private function buildRealAdapter(ConfigRepository $config, LogRepository $log): Adapter
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturnCallback(fn() => new RealCurl());
        $psr17 = new Psr17Factory();
        return new Adapter($config, $brand, $factory, $log, new NullApiTranslator(), $psr17, $psr17, $psr17);
    }
}
