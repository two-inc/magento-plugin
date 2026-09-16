<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Magento\Framework\Cache\Config;

use Magento\Framework\Cache\ConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Plugin\Magento\Framework\Cache\Config\BrandCacheTypeLabels;

/**
 * Cache Management is two clicks from the admin dashboard and needs no locale
 * switch, so a literal product name there is visible on every install.
 */
class BrandCacheTypeLabelsTest extends TestCase
{
    private const TYPES = [
        'two_gateway' => ['label' => '%1 gateway', 'description' => 'Merchant profile fetched from %1.'],
        'config' => ['label' => 'Configuration', 'description' => 'Various XML configs.'],
    ];

    private function plugin(?string $provider): BrandCacheTypeLabels
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        if ($provider === null) {
            $registry->method('getProvider')->willThrowException(new \DomainException('no brands registered'));
        } else {
            $registry->method('getProvider')->willReturn($provider);
        }

        return new BrandCacheTypeLabels($registry);
    }

    /**
     * @dataProvider installations
     */
    public function testTheCacheTypeCarriesTheActiveBrandsProviderName(
        ?string $provider,
        string $expectedLabel,
        string $expectedDescription,
        string $description
    ): void {
        $result = $this->plugin($provider)->afterGetTypes(
            $this->createMock(ConfigInterface::class),
            self::TYPES
        );

        $this->assertSame($expectedLabel, $result['two_gateway']['label'], $description);
        $this->assertSame($expectedDescription, $result['two_gateway']['description'], $description);
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: string, 3: string}>
     */
    public static function installations(): array
    {
        return [
            'vanilla' => ['Two', 'Two gateway', 'Merchant profile fetched from Two.', 'the unbranded install reads as before'],
            'overlay' => ['Acme Pay', 'Acme Pay gateway', 'Merchant profile fetched from Acme Pay.', 'a debranded install names its own provider'],
            'unresolvable brand' => [null, '%1 gateway', 'Merchant profile fetched from %1.', 'an unresolvable brand leaves cache management standing'],
        ];
    }

    public function testEveryOtherCacheTypeIsLeftAlone(): void
    {
        $result = $this->plugin('Acme Pay')->afterGetTypes(
            $this->createMock(ConfigInterface::class),
            self::TYPES
        );

        $this->assertSame(self::TYPES['config'], $result['config']);
    }

    public function testASingleTypeLookupIsRewrittenToo(): void
    {
        $plugin = $this->plugin('Acme Pay');
        $subject = $this->createMock(ConfigInterface::class);

        $this->assertSame(
            'Acme Pay gateway',
            $plugin->afterGetType($subject, self::TYPES['two_gateway'], 'two_gateway')['label']
        );
        $this->assertSame(
            self::TYPES['config'],
            $plugin->afterGetType($subject, self::TYPES['config'], 'config')
        );
    }
}
