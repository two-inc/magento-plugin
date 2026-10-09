<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Address;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;

/**
 * The store's own region rows for a country (`directory_country_region`), so a
 * merchant who has edited the list is matched against what their checkout
 * actually offers.
 */
class RegionDirectory
{
    /** @var array<string, array<int, string>> region id => code, per country */
    private array $byCountry = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param string $countryId ISO 3166-1 alpha-2
     * @return array<int, string> region id => region code; empty for a country with no regions
     */
    public function forCountry(string $countryId): array
    {
        if (!isset($this->byCountry[$countryId])) {
            $regions = [];
            $collection = $this->collectionFactory->create();
            $collection->addCountryFilter($countryId);
            foreach ($collection as $region) {
                $regions[(int)$region->getRegionId()] = (string)$region->getCode();
            }
            $this->byCountry[$countryId] = $regions;
        }

        return $this->byCountry[$countryId];
    }
}
