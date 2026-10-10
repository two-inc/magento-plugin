<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Sales\Model\Order;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Backend\TaxCodeMap as TaxCodeMapBackend;
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * Adds a Two `tax_code` to every line composed at a 0% tax rate (TWO-24877,
 * TWO-26153), from the merchant's rows in "Tax codes for 0% lines". The
 * merchant decides how their shop levies tax; the plugin only reads which of
 * their rows a line falls under. For each 0% line the first match wins:
 *
 * 1. Exempt buyer: the billing country and the tax address (the address core
 *    taxes on, normally delivery) are both in the EU VAT area and not the
 *    merchant's country (which must be known), and the buyer VAT number is
 *    not empty. The line's
 *    product tax class's exempt row. A tax address outside the EU VAT area
 *    (an export) skips this step.
 * 2. The shop's 0% rate that matched the tax address for the class: that
 *    rate's row. A line whose class matches a rate above 0% gets no code.
 * 3. No rate matched: the class's no-rule row.
 * 4. A line with no class (fee lines of type OTHER, a flat-rate payment terms
 *    fee, the refund adjustment, a product line no item matched) takes the one
 *    code the order's lines coded by steps 1 to 3 share; none if they disagree.
 * 5. Otherwise no code. The plugin never refuses an order over a missing code:
 *    Two's API validates what is sent.
 *
 * A matched row left on (none) gives no code; it never falls through to a
 * later step.
 *
 * Placement records what each line resolved to, "no code" included, on the
 * order (STORED_CODES): product lines by quote item id, then shipping, the
 * payment terms fee, and one shared key for every other fee line, since those
 * all resolve alike and a fee can change id between placement and a later
 * request. Every later request on that order reads the record rather
 * than resolving again, so a changed address, mapping or product tax class
 * never moves a placed order. A line the record does not cover (an order placed
 * before it existed, or a line placement never sent, such as a refund
 * adjustment) is resolved as at placement, step 4 sharing the codes steps 1
 * to 3 gave at placement (SHARED_KEY). A record written before that key
 * existed shares the codes its recorded lines carry, the `fee` line aside.
 *
 * Lines at any other rate are left exactly as composed.
 */
class TaxCodeResolver
{
    /** sales_order column: JSON of line key => code or null, written at placement. */
    public const STORED_CODES = 'two_tax_codes';

    /**
     * The 27 member states, plus Monaco, which is inside the EU VAT area
     * through France. With Northern Ireland (NI_COUNTRY, NI_POSTCODE_PREFIX)
     * this is the EU VAT area of step 1.
     */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    ];

    /** Northern Ireland, which shops hold as GB with a BT postcode, is in the EU VAT area for goods. */
    private const NI_COUNTRY = 'GB';
    private const NI_POSTCODE_PREFIX = 'BT';

    /** The record key every fee line of type OTHER shares. */
    private const FEE_KEY = 'fee';

    /**
     * The record key listing the codes steps 1 to 3 gave at placement, which a
     * later request's lines with no class share (step 4); absent when there
     * were none.
     */
    private const SHARED_KEY = 'shared';

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

    /**
     * @var ShopTaxRates
     */
    private $shopTaxRates;

    public function __construct(
        ConfigRepository $configRepository,
        RecordProvider $recordProvider,
        ShopTaxRates $shopTaxRates
    ) {
        $this->configRepository = $configRepository;
        $this->recordProvider = $recordProvider;
        $this->shopTaxRates = $shopTaxRates;
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
        // Step 4's pool: the codes steps 1 to 3 gave, here and at placement.
        $shared = [];
        foreach ($this->recordedShared($stored) as $code) {
            if (is_string($code)) {
                $shared[$code] = true;
            }
        }
        $keyless = [];

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
                $classId = $this->lineClass($line, $item ?: null, $context);
                if ($classId === null) {
                    $keyless[$key] = $storeKey;
                    continue;
                }
                $code = $this->resolve($order, $classId, $context);
                if ($code !== null) {
                    $shared[$code] = true;
                }
            }
            $lineItems[$key] = $this->code($lineItems[$key], $code);
            if ($placing && $storeKey !== null) {
                $record[$storeKey] = $code;
            }
        }

        foreach ($keyless as $key => $storeKey) {
            $code = count($shared) === 1 ? (string)key($shared) : null;
            $lineItems[$key] = $this->code($lineItems[$key], $code);
            if ($placing && $storeKey !== null) {
                $record[$storeKey] = $code;
            }
        }

        if ($placing && $shared !== []) {
            $record[self::SHARED_KEY] = array_keys($shared);
        }
        if ($placing) {
            $order->setData(self::STORED_CODES, (string)json_encode($record, JSON_FORCE_OBJECT));
        }

        return $lineItems;
    }

    /**
     * The order's buyer VAT number as entered, trimmed of leading and trailing
     * whitespace and nothing else: the billing address VAT id, then the
     * customer's tax/VAT number. A
     * billing VAT id that a VAT check which got an answer marked invalid stops
     * there with no number: the customer's tax/VAT number is often the same
     * number, so falling back to it would send the refused one. A check whose
     * request failed (the VAT service down or unreachable) also stores the
     * number as invalid, so that alone never drops it. '' when the order holds
     * neither.
     */
    public static function buyerVatNumber(Order $order): string
    {
        $billing = $order->getBillingAddress();
        if ($billing) {
            $raw = $billing->getVatId();
            $vat = is_scalar($raw) ? trim((string)$raw) : '';
            if ($vat !== '') {
                $checked = $billing->getVatIsValid();
                $refused = (bool)$billing->getVatRequestSuccess()
                    && $checked !== null && $checked !== '' && !(bool)$checked;

                return $refused ? '' : $vat;
            }
        }
        $raw = $order->getCustomerTaxvat();

        return is_scalar($raw) ? trim((string)$raw) : '';
    }

    /**
     * The buyer VAT number order create sends as `buyer_vat_number`, or null
     * to leave the key out: only for a Spanish merchant and a buyer company
     * outside Spain (TWO-26153). The API requires a Spanish buyer's VAT number
     * to equal its organisation number, so one is never sent for a Spanish
     * buyer, and every other merchant's payload is unchanged.
     */
    public function vatNumberToSend(Order $order): ?string
    {
        $billing = $order->getBillingAddress();
        $buyerCountry = $billing ? strtoupper(trim((string)$billing->getCountryId())) : '';
        if ($buyerCountry === 'ES' || $this->merchantCountry((int)$order->getStoreId()) !== 'ES') {
            return null;
        }
        $vat = self::buyerVatNumber($order);

        return $vat !== '' ? $vat : null;
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
     * Step 4's pool from a later request's record: its SHARED_KEY list, or, in
     * a record written before that key existed, every code its lines carry
     * other than the fee line's, which step 4 gave itself.
     *
     * @param array<string, mixed>|null $stored
     * @return array
     */
    private function recordedShared(?array $stored): array
    {
        if ($stored === null) {
            return [];
        }
        if (array_key_exists(self::SHARED_KEY, $stored)) {
            return (array)$stored[self::SHARED_KEY];
        }
        unset($stored[self::FEE_KEY]);

        return array_values($stored);
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

        return self::FEE_KEY;
    }

    /**
     * Everything resolution reads from the order and the configuration, read once per payload.
     */
    private function context(Order $order): array
    {
        $storeId = (int)$order->getStoreId();
        $billing = $order->getBillingAddress();
        $merchantCountry = $this->merchantCountry($storeId);
        $buyerCountry = $billing ? strtoupper(trim((string)$billing->getCountryId())) : '';
        $buyerPostcode = $billing ? (string)$billing->getPostcode() : '';
        $buyerVat = self::buyerVatNumber($order);
        $map = $this->configRepository->getTaxCodeMap($storeId);

        return [
            'store_id' => $storeId,
            'map' => $map,
            // With no row set nothing reads it, so the shop's tax is not looked up.
            'exempt' => $map !== [] && $buyerVat !== '' && $merchantCountry !== ''
                && self::inEuVatAreaAbroad($buyerCountry, $buyerPostcode, $merchantCountry)
                && $this->taxAddressAbroad($order, $merchantCountry),
        ];
    }

    private function taxAddressAbroad(Order $order, string $merchantCountry): bool
    {
        $taxAddress = $this->shopTaxRates->taxAddress($order);

        return self::inEuVatAreaAbroad($taxAddress['country'], $taxAddress['postcode'], $merchantCountry);
    }

    /**
     * In the EU VAT area (the 27 member states, Monaco, and Northern Ireland
     * as GB with a BT postcode) and not the merchant's country.
     */
    private static function inEuVatAreaAbroad(string $country, string $postcode, string $merchantCountry): bool
    {
        $country = strtoupper(trim($country));
        $inArea = in_array($country, self::EU, true) || ($country === self::NI_COUNTRY
            && strpos(strtoupper(trim($postcode)), self::NI_POSTCODE_PREFIX) === 0);

        return $inArea && $country !== $merchantCountry;
    }

    /**
     * The line's product tax class, null for a line with none.
     */
    private function lineClass(array $line, $item, array $context): ?int
    {
        if ($item !== null) {
            return $this->productTaxClassId($item);
        }
        if (($line['type'] ?? '') === 'SHIPPING_FEE') {
            // Core's None is class 0, which the repository reports as null.
            return $this->configRepository->getShippingTaxClassId($context['store_id']) ?? 0;
        }
        if (($line['order_item_id'] ?? null) === 'surcharge') {
            return $this->configRepository->getSurchargeTaxClassId($context['store_id']);
        }

        return null;
    }

    /**
     * Steps 1 to 3 for a line with a product tax class: the code of the row
     * it falls under, or null.
     */
    private function resolve(Order $order, int $classId, array $context): ?string
    {
        $row = $context['map'] !== [] ? $this->matchedRow($order, $classId, $context) : null;

        return $row !== null ? ($context['map'][$row] ?? null) : null;
    }

    /**
     * The key of the row the line falls under, or null when the shop's rate
     * for it is not 0%.
     */
    private function matchedRow(Order $order, int $classId, array $context): ?string
    {
        if ($context['exempt']) {
            return TaxCodeMapBackend::exemptKey($classId);
        }
        $rates = $classId > 0 ? $this->shopTaxRates->rates($order, $classId) : [];
        if ($rates === []) {
            return TaxCodeMapBackend::noRuleKey($classId);
        }
        foreach ($rates as $rate) {
            if ($rate['percent'] != 0.0) {
                return null;
            }
        }

        return TaxCodeMapBackend::rateKey($classId, $rates[0]['code']);
    }

    private function code(array $line, ?string $code): array
    {
        if ($code !== null) {
            $line['tax_code'] = $code;
        }

        return $line;
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

    private function merchantCountry(int $storeId): string
    {
        $record = $this->recordProvider->getRecord($storeId);
        $country = $record['country_code'] ?? '';

        return is_string($country) ? strtoupper(trim($country)) : '';
    }
}
