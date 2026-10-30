<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\App\ResourceConnection;

/**
 * The shop's 0% tax rates, by the product tax classes whose tax rules use
 * them (TWO-26153): the rows of the "Tax codes for 0% lines" setting. Rates
 * above 0% are left out, since they never give a 0% line.
 */
class ZeroTaxRates
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @return array<int, array<int, array{code: string, country: string, postcode: string}>>
     *         product tax class id => its 0% rates, ordered by rate code
     */
    public function byClass(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(
                ['calculation' => $this->resourceConnection->getTableName('tax_calculation')],
                ['product_tax_class_id']
            )
            ->join(
                ['rate' => $this->resourceConnection->getTableName('tax_calculation_rate')],
                'rate.tax_calculation_rate_id = calculation.tax_calculation_rate_id',
                ['code', 'tax_country_id', 'tax_postcode']
            )
            ->where('rate.rate = ?', 0)
            ->order(['calculation.product_tax_class_id', 'rate.code']);

        $rates = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rates[(int)$row['product_tax_class_id']][] = [
                'code' => (string)$row['code'],
                'country' => (string)$row['tax_country_id'],
                'postcode' => (string)$row['tax_postcode'],
            ];
        }

        return $rates;
    }
}
