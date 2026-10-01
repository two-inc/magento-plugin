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
 * Adds a Two `tax_code` to every line composed at a 0% tax rate (TWO-24877,
 * TWO-26151).
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
 * order (STORED_CODES): product lines by quote item id, then shipping, the
 * payment terms fee, and one shared key for every other fee line, since those
 * all resolve alike and a fee can change id between placement and a later
 * request. Every later request on that order reads the record rather
 * than resolving again, so a changed address, mapping or product tax class
 * never moves a placed order. A line the record does not cover (an order placed
 * before it existed, or a line placement never sent, such as a refund
 * adjustment) is resolved as at placement.
 *
 * Lines at any other rate are left exactly as composed, and so is every line of
 * a non-Spanish merchant with no mapping.
 */
class TaxCodeResolver
{
    public const EXPORT = 'ES_IVA_EXPORT';
    public const INTRA_COMMUNITY = 'ES_IVA_INTRA_COMMUNITY';
    public const INTRA_COMMUNITY_SERVICES = 'ES_IVA_INTRA_COMMUNITY_SERVICES';
    public const NON_EU_SERVICES = 'ES_IVA_NON_EU_SERVICES';

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

    /** The line types the composers give product lines; nothing else is an order item. */
    private const PRODUCT_LINE_TYPES = ['PHYSICAL', 'DIGITAL'];

    /** The line types of fee lines: "Other charges", fee providers and the payment terms fee. */
    private const FEE_LINE_TYPES = ['OTHER', 'BUYER_FEE'];

    /**
     * The refund adjustment line, which keeps resolving live (TWO-24877): it
     * is never placed, and how it should be split is still open.
     */
    private const REFUND_ADJUSTMENT_ID = 'adjustment';

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
     * @param array $items order items by line key, for product lines whose order_item_id is not an id yet
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
            $item = null;
            if (in_array($line['type'] ?? '', self::PRODUCT_LINE_TYPES, true)) {
                $item = $items[$key] ?? (is_numeric($itemId) ? $order->getItemById($itemId) : null);
            }
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
     * Goods go by the delivery address; services by where the buyer company
     * is, where the Canaries, Ceuta and Melilla count as outside the EU.
     *
     * @param bool $isService
     * @param string $destCountry delivery country (billing when there is no delivery address)
     * @param string $destPostcode delivery postcode
     * @param string $buyerCountry buyer company country
     * @param string $buyerPostcode billing postcode
     * @return string|null
     */
    public static function derive(
        bool $isService,
        string $destCountry,
        string $destPostcode,
        string $buyerCountry,
        string $buyerPostcode
    ): ?string {
        $destCountry = strtoupper(trim($destCountry));
        $buyerCountry = strtoupper(trim($buyerCountry));
        $buyerInOtherEuState = $buyerCountry !== 'ES' && in_array($buyerCountry, self::EU, true);

        if ($isService) {
            if ($buyerInOtherEuState) {
                return self::INTRA_COMMUNITY_SERVICES;
            }
            if ($buyerCountry !== '' && self::outsideEu($buyerCountry, $buyerPostcode)) {
                return self::NON_EU_SERVICES;
            }
            return null;
        }
        if ($destCountry === '') {
            return null;
        }
        if (self::outsideEu($destCountry, $destPostcode)) {
            return self::EXPORT;
        }
        if ($destCountry !== 'ES' && $buyerInOtherEuState) {
            return self::INTRA_COMMUNITY;
        }

        return null;
    }

    /**
     * Outside the EU VAT area: a country outside the EU, or a Spanish postcode
     * in the Canaries, Ceuta or Melilla.
     */
    private static function outsideEu(string $country, string $postcode): bool
    {
        return !in_array($country, self::EU, true)
            || ($country === 'ES' && in_array(substr(trim($postcode), 0, 2), self::ES_OUTSIDE_VAT_AREA, true));
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
     * the item carries from placement on; shipping by name, since a refund's
     * shipping line has no order_item_id; the payment terms fee, which has its
     * own tax class; and every other fee line under one key, whatever its id.
     * Null for a line the record does not cover.
     */
    private function storeKey(array $line, $item): ?string
    {
        $type = $line['type'] ?? '';
        $lineId = $line['order_item_id'] ?? null;
        if ($item !== null) {
            $quoteItemId = $item->getQuoteItemId();
            return is_numeric($quoteItemId) ? 'item:' . (int)$quoteItemId : null;
        }
        if ($type === 'SHIPPING_FEE') {
            return 'shipping';
        }
        if ($lineId === 'surcharge') {
            return 'surcharge';
        }
        if ($lineId === self::REFUND_ADJUSTMENT_ID || !in_array($type, self::FEE_LINE_TYPES, true)) {
            return null;
        }

        return 'fee';
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
            'buyer_postcode' => $billing ? (string)$billing->getPostcode() : '',
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
        } elseif (in_array($line['type'] ?? '', self::PRODUCT_LINE_TYPES, true)) {
            // A product line no item matched: its own type still says goods or service.
            [$classId, $isService] = [null, $line['type'] === 'DIGITAL'];
        } else {
            [$classId, $isService] = [null, !$context['has_goods']];
        }

        $code = $classId !== null ? ($context['map'][(string)$classId] ?? null) : null;
        if ($code === null && $context['derive']) {
            $code = self::derive(
                $isService,
                $context['dest_country'],
                $context['dest_postcode'],
                $context['buyer_country'],
                $context['buyer_postcode']
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
     * Virtual and downloadable products are services; so is any item Magento
     * marked virtual (a bundle, gift card or configurable with nothing to ship).
     *
     * @param Order\Item $item
     */
    private static function isService($item): bool
    {
        return (bool)$item->getIsVirtual()
            || in_array((string)$item->getProductType(), self::SERVICE_TYPES, true);
    }

    private function merchantCountry(int $storeId): string
    {
        $record = $this->recordProvider->getRecord($storeId);
        $country = $record['country_code'] ?? '';

        return is_string($country) ? strtoupper(trim($country)) : '';
    }
}
