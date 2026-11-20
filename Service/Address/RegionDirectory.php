<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Address;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * The store's own region rows for a country (`directory_country_region`), so a
 * merchant who has edited the list is matched against what their checkout
 * actually offers.
 */
class RegionDirectory
{
    /** @var array<string, array<int, string>> region id => code, per country */
    private array $byCountry = [];

    /** @var array<string, array<int, string[]>> region id => names, per country */
    private array $namesByCountry = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly LogRepository $logRepository
    ) {
    }

    /**
     * @param string $countryId ISO 3166-1 alpha-2
     * @return array<int, string> region id => region code; empty for a country with no regions
     */
    public function forCountry(string $countryId): array
    {
        $this->load($countryId);

        return $this->byCountry[$countryId];
    }

    /**
     * Each region's default name and, where the store's locale has one, its
     * translated name (TWO-26266).
     *
     * @param string $countryId ISO 3166-1 alpha-2
     * @return array<int, string[]> region id => its distinct non-empty names
     */
    public function namesForCountry(string $countryId): array
    {
        $this->load($countryId);

        return $this->namesByCountry[$countryId];
    }

    /**
     * One read per country. A failed read is logged once and answers no
     * regions, so the lookup it serves is still relayed, with its addresses
     * as answered.
     */
    private function load(string $countryId): void
    {
        if (isset($this->byCountry[$countryId])) {
            return;
        }
        $codes = [];
        $names = [];
        try {
            $collection = $this->collectionFactory->create();
            $collection->addCountryFilter($countryId);
            foreach ($collection as $region) {
                $regionId = (int)$region->getRegionId();
                $codes[$regionId] = (string)$region->getCode();
                $names[$regionId] = array_values(array_unique(array_filter(
                    [(string)$region->getDefaultName(), (string)$region->getName()],
                    static fn(string $name) => trim($name) !== ''
                )));
            }
        } catch (\Throwable $e) {
            $this->logRepository->addErrorLog(
                '[region-directory] read failed',
                ['country' => $countryId, 'error' => $e->getMessage()]
            );
            $codes = [];
            $names = [];
        }
        $this->byCountry[$countryId] = $codes;
        $this->namesByCountry[$countryId] = $names;
    }
}
