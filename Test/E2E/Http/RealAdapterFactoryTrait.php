<?php
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Http;

use Magento\Framework\HTTP\Client\CurlFactory;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Service\Api\Adapter;

trait RealAdapterFactoryTrait
{
    private function buildRealAdapter(ConfigRepository $config, LogRepository $log): Adapter
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturnCallback(fn() => new RealCurl());
        return new Adapter($config, $brand, $factory, $log, new NullApiTranslator());
    }
}
