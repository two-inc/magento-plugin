<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Cron\RefreshFxRates;
use Two\Gateway\Service\Fx\RateTableProvider;

class RefreshFxRatesTest extends TestCase
{
    /** @var RateTableProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $rateTableProvider;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    protected function setUp(): void
    {
        $this->rateTableProvider = $this->createMock(RateTableProvider::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    private function cron(): RefreshFxRates
    {
        return new RefreshFxRates($this->rateTableProvider, $this->storeManager, $this->configRepository);
    }

    /**
     * @return StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function store(int $id)
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        return $store;
    }

    public function testRefreshesOncePerDistinctApiKey(): void
    {
        // Default scope and store 1 share a key (one cache entry — one
        // refresh); store 2 has its own key and gets its own refresh.
        $this->storeManager->method('getStores')->willReturn([$this->store(1), $this->store(2)]);
        $this->configRepository->method('getApiKey')->willReturnMap([
            [null, 'key-a'],
            [1, 'key-a'],
            [2, 'key-b'],
        ]);
        $calls = [];
        $this->rateTableProvider->method('refresh')->willReturnCallback(
            function (?int $storeId) use (&$calls) {
                $calls[] = $storeId;
                return true;
            }
        );

        $this->cron()->execute();

        $this->assertSame([null, 2], $calls);
    }

    public function testScopesWithoutApiKeyAreSkipped(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1)]);
        $this->configRepository->method('getApiKey')->willReturn('');
        $this->rateTableProvider->expects($this->never())->method('refresh');

        $this->cron()->execute();
    }
}
