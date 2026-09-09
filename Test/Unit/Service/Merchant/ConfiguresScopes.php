<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;

/**
 * A store tree plus a config fake that inherits like Magento's: store -> its
 * website -> default, and a store-scope read with no id resolving through
 * the current store. Both spellings of each scope name resolve, as the config
 * layer's own do. Needs $this->storeManager and $this->configRepository.
 */
trait ConfiguresScopes
{
    /**
     * @return StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function store(int $id, int $websiteId = 1)
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getWebsiteId')->willReturn($websiteId);

        return $store;
    }

    /**
     * Store views as [id => websiteId]; config as "<scopeKey>" => [key, mode]
     * where scopeKey is a store id, "websites:<id>" or "default:".
     *
     * @param array<int,int> $stores
     * @param array<string,array{0: string, 1: string}> $config
     * @param int|null $currentStoreId what a store-scope read with no id resolves through; null for none
     */
    private function configure(array $stores, array $config, ?int $currentStoreId = null): void
    {
        $this->storeManager->method('getStores')->willReturn(
            array_map(
                function (int $id, int $websiteId) {
                    return $this->store($id, $websiteId);
                },
                array_keys($stores),
                $stores
            )
        );
        $this->storeManager->method('getStore')->willReturnCallback(
            function ($id) use ($stores) {
                if (!isset($stores[(int)$id])) {
                    throw new NoSuchEntityException(__('gone'));
                }
                return $this->store((int)$id, $stores[(int)$id]);
            }
        );
        // A website exists when a store view belongs to it or config is set at it.
        $this->storeManager->method('getWebsite')->willReturnCallback(
            function ($id) use ($stores, $config) {
                if (!in_array((int)$id, $stores, true) && !isset($config['websites:' . (int)$id])) {
                    throw new NoSuchEntityException(__('gone'));
                }
                $website = $this->createMock(WebsiteInterface::class);
                $website->method('getId')->willReturn((int)$id);
                return $website;
            }
        );
        $lookup = function (int $index) use ($config, $stores, $currentStoreId) {
            return function (?int $storeId = null, ?string $scope = null) use ($config, $stores, $currentStoreId, $index) {
                if ($scope === null || $scope === 'stores' || $scope === 'store') {
                    $storeId = $storeId ?? $currentStoreId;
                    $chain = $storeId === null
                        ? []
                        : [(string)$storeId, 'websites:' . ($stores[$storeId] ?? 0), 'default:'];
                } elseif ($scope === 'websites' || $scope === 'website') {
                    $chain = ['websites:' . $storeId, 'default:'];
                } else {
                    $chain = ['default:'];
                }
                foreach ($chain as $scopeKey) {
                    if (isset($config[$scopeKey])) {
                        return $config[$scopeKey][$index];
                    }
                }
                return $index === 0 ? '' : 'sandbox';
            };
        };
        $this->configRepository->method('getApiKey')->willReturnCallback($lookup(0));
        $this->configRepository->method('getMode')->willReturnCallback($lookup(1));
    }
}
