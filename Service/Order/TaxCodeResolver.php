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
 * Placement records what each line resolved to, "no code" included, on the
 * order (STORED_CODES). Every later request on that order reads the record
 * rather than resolving again, so a changed address, mapping or product tax
 * class never moves a placed order. A line the record does not cover (an order
 * placed before it existed, or a line placement never sent, such as a refund
 * adjustment) is resolved as at placement.
 *
 * Lines at any other rate are left exactly as composed, and so is every line of
 * a non-Spanish merchant with no mapping.
 */
class TaxCodeResolver
{
    public const EXPORT = 'ES_IVA_EXPORT';
    public const INTRA_COMMUNITY = 'ES_IVA_INTRA_COMMUNITY';
    public const REVERSE_CHARGE = 'ES_IVA_REVERSE_CHARGE';

    /** sales_order column: JSON of line key => code or null, written at placement. */
    public const STORED_CODES = 'two_tax_codes';

    /** The 27 member states, plus Monaco, which is inside the EU VAT area through France. */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    ];

    /** Spanish postcodes outside the EU VAT area: the Canaries (35, 38), Ceuta (51) and Melilla (52). */
    private const ES_OUTSIDE_VAT_AREA = ['35', '38', '51', '52'];

    /** Product types that are always services. */
    private const SERVICE_TYPES = ['virtual', 'downloadable'];

    /** Product types that are services when every part of them is virtual. */
    private const SERVICE_WHEN_VIRTUAL_TYPES = ['bundle', 'giftcard'];

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
     * @param array $items order items by line key, for lines whose order_item_id is not an id yet
     * @return array
     */
    public function apply(array $lineItems, Order $order, array $items = []): array
    {
        $placing = !$order->getId();
        $stored = $placing ? null : self::decodeStored($order->getData(self::STORED_CODES));
        $context = null;
        $record = [];

        foreach ($lineItems as $key => $line) {
            if (!is_array($line) || !isset($line['tax_rate']) || (float)$line['tax_rate'] != 0.0
                || !empty($line['tax_code'])
            ) {
                continue;
            }
            $itemId = $line['order_item_id'] ?? null;
            $item = $items[$key] ?? (is_numeric($itemId) ? $order->getItemById($itemId) : null);
            $storeKey = $this->storeKey($line, $item ?: null);

            if ($stored !== null && $storeKey !== null && array_key_exists($storeKey, $stored)) {
                $code = $stored[$storeKey];
            } else {
                $context = $context ?? $this->context($order);
                $code = $this->resolve($line, $item ?: null, $context);
            }
            if ($placing && $storeKey !== null) {
                $record[$storeKey] = $code;
            }
            if ($code !== null) {
                $lineItems[$key]['tax_code'] = $code;
            }
        }

        if ($placing) {
            $order->setData(self::STORED_CODES, (string)json_encode($record, JSON_FORCE_OBJECT));
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
     * @param mixed $stored
     * @return array<string, string|null>|null null when the order has no record
     */
    private static function decodeStored($stored): ?array
    {
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Where the line's code is recorded: product lines by quote item id, which
     * the item carries from placement on, and the shipping and surcharge lines
     * by name. Null for a line the record does not cover.
     */
    private function storeKey(array $line, $item): ?string
    {
        if ($item !== null) {
            $quoteItemId = $item->getQuoteItemId();
            return is_numeric($quoteItemId) ? 'item:' . (int)$quoteItemId : null;
        }
        if (($line['type'] ?? '') === 'SHIPPING_FEE') {
            return 'shipping';
        }

        return ($line['order_item_id'] ?? null) === 'surcharge' ? 'surcharge' : null;
    }

    /**
     * Everything resolution reads from the order and the configuration, read once per payload.
     */
    private function context(Order $order): array
    {
        $storeId = (int)$order->getStoreId();
        $billing = $order->getBillingAddress();
        $destination = $order->getShippingAddress() ?: $billing;
        $hasGoods = false;
        foreach ($order->getAllVisibleItems() as $visible) {
            $hasGoods = $hasGoods || !self::isService($visible);
        }

        return [
            'store_id' => $storeId,
            'map' => $this->configRepository->getTaxCodeMap($storeId),
            'derive' => $this->merchantCountry($storeId) === 'ES',
            'dest_country' => $destination ? (string)$destination->getCountryId() : '',
            'dest_postcode' => $destination ? (string)$destination->getPostcode() : '',
            'buyer_country' => $billing ? (string)$billing->getCountryId() : '',
            'has_goods' => $hasGoods,
        ];
    }

    private function resolve(array $line, $item, array $context): ?string
    {
        if ($item !== null) {
            [$classId, $isService] = [$this->productTaxClassId($item), self::isService($item)];
        } elseif (($line['type'] ?? '') === 'SHIPPING_FEE') {
            // Core's None is class 0, which the repository reports as null.
            [$classId, $isService] = [
                $this->configRepository->getShippingTaxClassId($context['store_id']) ?? 0,
                !$context['has_goods'],
            ];
        } elseif (($line['order_item_id'] ?? null) === 'surcharge') {
            [$classId, $isService] = [
                $this->configRepository->getSurchargeTaxClassId($context['store_id']),
                !$context['has_goods'],
            ];
        } else {
            [$classId, $isService] = [null, !$context['has_goods']];
        }

        $code = $classId !== null ? ($context['map'][(string)$classId] ?? null) : null;
        if ($code === null && $context['derive']) {
            $code = self::derive(
                $isService,
                $context['dest_country'],
                $context['dest_postcode'],
                $context['buyer_country']
            );
        }

        return $code;
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

    /**
     * Virtual and downloadable products are services; so is a bundle or gift
     * card Magento marked virtual, which it does only when nothing in it ships.
     *
     * @param Order\Item $item
     */
    private static function isService($item): bool
    {
        $type = (string)$item->getProductType();

        return in_array($type, self::SERVICE_TYPES, true)
            || (in_array($type, self::SERVICE_WHEN_VIRTUAL_TYPES, true) && (bool)$item->getIsVirtual());
    }

    private function merchantCountry(int $storeId): string
    {
        $record = $this->recordProvider->getRecord($storeId);
        $country = $record['country_code'] ?? '';

        return is_string($country) ? strtoupper(trim($country)) : '';
    }
}
