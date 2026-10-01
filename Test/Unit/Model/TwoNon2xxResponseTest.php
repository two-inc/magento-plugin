<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;

/**
 * TWO-26150: a non-2xx response with no error fields in its body (a 405, or
 * a gateway's HTML error page) used to read as success. Runs the real
 * Adapter into the real getErrorFromResponse(), so the shape the adapter
 * actually produces for such a body is what gets judged.
 */
class TwoNon2xxResponseTest extends TestCase
{
    private const HTML = '<html><head><title>Error</title></head><body><h1>Error</h1></body></html>';

    /**
     * @return array<int, array{int, string, string}> [status, body, description]
     */
    public static function non2xxCases(): array
    {
        return [
            [405, self::HTML, '405 Method Not Allowed with an HTML body'],
            [500, self::HTML, '500 with an HTML body'],
            [502, self::HTML, '502 from a gateway with an HTML body'],
            [503, '{"detail":"unavailable"}', '503 with JSON but no error fields'],
        ];
    }

    #[DataProvider('non2xxCases')]
    public function testNon2xxIsAnError(int $status, string $body, string $description): void
    {
        $response = $this->adapterReturning($status, $body)->execute('/v1/order/abc', ['x' => 1], 'PUT');

        $error = $this->twoModel()->getErrorFromResponse($response);

        $this->assertNotNull($error, $description);
        $this->assertStringContainsString((string)$status, $error->render(), $description);
    }

    private function adapterReturning(int $status, string $body): Adapter
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn('https://api.example.test');
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn('test-key');
        $config->method('getCustomHeaders')->willReturn([]);

        return new Adapter(
            $config,
            $this->brandRegistry(),
            $curlFactory,
            $this->createMock(LogRepository::class),
            new NullApiTranslator()
        );
    }

    private function twoModel(): Two
    {
        $model = $this->getMockBuilder(Two::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionClass(Two::class);
        foreach (['configRepository' => $this->createMock(ConfigRepository::class),
                     'brandRegistry' => $this->brandRegistry()] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($model, $value);
        }

        return $model;
    }

    private function brandRegistry(): BrandRegistryInterface
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');

        return $brand;
    }
}
