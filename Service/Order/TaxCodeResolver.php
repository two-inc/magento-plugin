<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Sales\Model\Order;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * Adds a Two `tax_code` to every line composed at a 0% tax rate (TWO-24877).
 *
 * 1. The merchant's mapping of the line's tax class, when there is one.
 * 2. Otherwise, for a Spanish merchant only, a code derived from the order:
 *    goods by where they are delivered, services by where the buyer company
 *    is. Shipping and fee lines are goods when the order has a physical
 *    product and services when it has none.
 * 3. Otherwise no code. The plugin never refuses an order over a missing code:
 *    Two's API validates what is sent.
 *
 * Lines at any other rate, and every line of a non-Spanish merchant with no
 * mapping, are left exactly as composed.
 */
class TaxCodeResolver
{
    public const EXPORT = 'ES_IVA_EXPORT';
    public const INTRA_COMMUNITY = 'ES_IVA_INTRA_COMMUNITY';
    public const REVERSE_CHARGE = 'ES_IVA_REVERSE_CHARGE';

    /** The 27 member states, plus Monaco, which is inside the EU VAT area through France. */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    ];

    /** Spanish postcodes outside the EU VAT area: the Canaries (35, 38), Ceuta (51) and Melilla (52). */
    private const ES_OUTSIDE_VAT_AREA = ['35', '38', '51', '52'];

    /** Product types that are services rather than goods. */
    private const SERVICE_TYPES = ['virtual', 'downloadable'];

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var RecordProvider
     */
    private $recordProvider;

    public function __construct(ConfigRepository $configRepository, RecordProvider $recordProvider)
    {
        $this->configRepository = $configRepository;
        $this->recordProvider = $recordProvider;
    }

    /**
     * @param array $lineItems composed lines, keys kept
     * @param Order $order the order the lines belong to
     * @return array
     */
    public function apply(array $lineItems, Order $order): array
    {
        $storeId = (int)$order->getStoreId();
        $map = $this->configRepository->getTaxCodeMap($storeId);
        $derive = $this->merchantCountry($storeId) === 'ES';
        if ($map === [] && !$derive) {
            return $lineItems;
        }

        $billing = $order->getBillingAddress();
        $destination = $order->getShippingAddress() ?: $billing;
        $destCountry = $destination ? (string)$destination->getCountryId() : '';
        $destPostcode = $destination ? (string)$destination->getPostcode() : '';
        $buyerCountry = $billing ? (string)$billing->getCountryId() : '';
        $orderHasGoods = $this->hasGoods($order);

        foreach ($lineItems as $key => $line) {
            if (!is_array($line) || !isset($line['tax_rate']) || (float)$line['tax_rate'] != 0.0
                || !empty($line['tax_code'])
            ) {
                continue;
            }
            [$classId, $isService] = $this->classify($line, $order, $orderHasGoods, $storeId);
            $code = $classId !== null ? ($map[(string)$classId] ?? null) : null;
            if ($code === null && $derive) {
                $code = self::derive($isService, $destCountry, $destPostcode, $buyerCountry);
            }
            if ($code !== null) {
                $lineItems[$key]['tax_code'] = $code;
            }
        }

        return $lineItems;
    }

    /**
     * The code a Spanish merchant's 0% line takes from the order, or null.
     *
     * @param bool $isService
     * @param string $destCountry delivery country (billing when there is no delivery address)
     * @param string $destPostcode delivery postcode
     * @param string $buyerCountry buyer company country
     * @return string|null
     */
    public static function derive(
        bool $isService,
        string $destCountry,
        string $destPostcode,
        string $buyerCountry
    ): ?string {
        $destCountry = strtoupper(trim($destCountry));
        $buyerCountry = strtoupper(trim($buyerCountry));
        $buyerInOtherEuState = $buyerCountry !== 'ES' && in_array($buyerCountry, self::EU, true);

        if ($isService) {
            return $buyerInOtherEuState ? self::REVERSE_CHARGE : null;
        }
        if ($destCountry === '') {
            return null;
        }
        if (!in_array($destCountry, self::EU, true)
            || ($destCountry === 'ES' && in_array(substr(trim($destPostcode), 0, 2), self::ES_OUTSIDE_VAT_AREA, true))
        ) {
            return self::EXPORT;
        }
        if ($destCountry !== 'ES' && $buyerInOtherEuState) {
            return self::INTRA_COMMUNITY;
        }

        return null;
    }

    /**
     * The line's tax class id (null when it has none) and whether it is a service.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function classify(array $line, Order $order, bool $orderHasGoods, int $storeId): array
    {
        $itemId = $line['order_item_id'] ?? null;
        if (is_numeric($itemId) && ($item = $order->getItemById($itemId))) {
            return [$this->productTaxClassId($item), self::isServiceType($item->getProductType())];
        }
        if (($line['type'] ?? '') === 'SHIPPING_FEE') {
            return [$this->configRepository->getShippingTaxClassId($storeId), !$orderHasGoods];
        }
        if ($itemId === 'surcharge') {
            return [$this->configRepository->getSurchargeTaxClassId($storeId), !$orderHasGoods];
        }

        return [null, !$orderHasGoods];
    }

    /**
     * The class Magento taxed the item by: a configurable item's is its child's.
     *
     * @param Order\Item $item
     * @return int|null
     */
    private function productTaxClassId($item): ?int
    {
        $children = $item->getChildrenItems() ?: [];
        $source = $item->getProductType() === 'configurable' && $children ? reset($children) : $item;
        $product = $source->getProduct();
        $classId = $product ? $product->getData('tax_class_id') : null;

        return is_numeric($classId) ? (int)$classId : null;
    }

    private function hasGoods(Order $order): bool
    {
        foreach ($order->getAllVisibleItems() as $item) {
            if (!self::isServiceType($item->getProductType())) {
                return true;
            }
        }

        return false;
    }

    private static function isServiceType($productType): bool
    {
        return in_array((string)$productType, self::SERVICE_TYPES, true);
    }

    private function merchantCountry(int $storeId): string
    {
        $record = $this->recordProvider->getRecord($storeId);
        $country = $record['country_code'] ?? '';

        return is_string($country) ? strtoupper(trim($country)) : '';
    }
}
