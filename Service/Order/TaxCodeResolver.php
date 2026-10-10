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
 * later step. Until derivation is removed, a Spanish merchant's line whose
 * class has no row mapped at all (or, for a line with no class, whose order
 * has no line coded by steps 1 to 3) still takes the code derive() works out.
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
 * to 3 gave at placement (SHARED_KEY).
 *
 * Lines at any other rate are left exactly as composed.
 */
class TaxCodeResolver
{
    public const EXPORT = 'ES_IVA_EXPORT';
    public const INTRA_COMMUNITY = 'ES_IVA_INTRA_COMMUNITY';
    public const INTRA_COMMUNITY_SERVICES = 'ES_IVA_INTRA_COMMUNITY_SERVICES';
    public const NON_EU_SERVICES = 'ES_IVA_NON_EU_SERVICES';

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

    /** Spanish postcodes outside the EU VAT area: the Canaries (35, 38), Ceuta (51) and Melilla (52). */
    private const ES_OUTSIDE_VAT_AREA = ['35', '38', '51', '52'];

    /** Northern Ireland, which shops hold as GB with a BT postcode, is in the EU VAT area for goods. */
    private const NI_COUNTRY = 'GB';
    private const NI_POSTCODE_PREFIX = 'BT';

    /** The record key every fee line of type OTHER shares. */
    private const FEE_KEY = 'fee';

    /**
     * The record key listing the codes steps 1 to 3 gave at placement, which a
     * later request's lines with no class share (step 4). Derived codes are
     * left out, as at placement; absent when there were none.
     */
    private const SHARED_KEY = 'shared';

    /** Greece's VAT prefix, which names GR. */
    private const VAT_PREFIX_GREECE = 'EL';

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
        // Step 4's pool: the codes steps 1 to 3 gave, here and at placement (never derived ones).
        $shared = [];
        foreach ((array)($stored[self::SHARED_KEY] ?? []) as $code) {
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
                [$classId, $isService] = $this->lineClass($line, $item ?: null, $context);
                if ($classId === null) {
                    $keyless[$key] = [$storeKey, $isService];
                    continue;
                }
                $code = $this->resolve($order, $classId, $isService, $context, $shared);
            }
            $lineItems[$key] = $this->code($lineItems[$key], $code);
            if ($placing && $storeKey !== null) {
                $record[$storeKey] = $code;
            }
        }

        foreach ($keyless as $key => [$storeKey, $isService]) {
            $code = count($shared) === 1 ? (string)key($shared) : null;
            if ($shared === [] && $context['derive']) {
                $code = $this->derived($isService, $context);
            }
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
     * The code a Spanish merchant's 0% line takes from the order, or null.
     * Goods go by the delivery address; services by where the buyer company
     * is, where the Canaries, Ceuta and Melilla count as outside the EU.
     * Either intra-community code also needs a buyer VAT number whose prefix
     * is an EU state other than the merchant's country (TWO-26153); without
     * one the line gets no code, never the non-EU or export code instead.
     *
     * @param bool $isService
     * @param string $destCountry delivery country (billing when there is no delivery address)
     * @param string $destPostcode delivery postcode
     * @param string $buyerCountry buyer company country
     * @param string $buyerPostcode billing postcode
     * @param string $merchantCountry the merchant record's country
     * @param string $buyerVat buyer VAT number as normaliseVatNumber() returns it, '' for none
     * @return string|null
     */
    public static function derive(
        bool $isService,
        string $destCountry,
        string $destPostcode,
        string $buyerCountry,
        string $buyerPostcode,
        string $merchantCountry,
        string $buyerVat
    ): ?string {
        $destCountry = strtoupper(trim($destCountry));
        $buyerCountry = strtoupper(trim($buyerCountry));
        $buyerInOtherEuState = $buyerCountry !== 'ES' && in_array($buyerCountry, self::EU, true);
        $intraCommunity = $buyerInOtherEuState
            && self::vatIsFromAnotherEuState($buyerVat, strtoupper(trim($merchantCountry)));

        if ($isService) {
            if ($buyerInOtherEuState) {
                return $intraCommunity ? self::INTRA_COMMUNITY_SERVICES : null;
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
        if ($destCountry !== 'ES' && $intraCommunity) {
            return self::INTRA_COMMUNITY;
        }

        return null;
    }

    /**
     * A VAT number as the buyer entered it, trimmed of leading and trailing
     * whitespace and nothing else: no case change, no characters removed, no
     * prefix added. '' when nothing is left.
     *
     * @param string $raw as the shop holds it
     */
    public static function normaliseVatNumber(string $raw): string
    {
        return trim($raw);
    }

    /**
     * The order's buyer VAT number, trimmed:
     * the billing address VAT id, then the customer's tax/VAT number. A
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
            $vat = is_scalar($raw) ? self::normaliseVatNumber((string)$raw) : '';
            if ($vat !== '') {
                $checked = $billing->getVatIsValid();
                $refused = (bool)$billing->getVatRequestSuccess()
                    && $checked !== null && $checked !== '' && !(bool)$checked;

                return $refused ? '' : $vat;
            }
        }
        $raw = $order->getCustomerTaxvat();

        return is_scalar($raw) ? self::normaliseVatNumber((string)$raw) : '';
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
     * Whether the VAT number's prefix names an EU state other than the
     * merchant's country, read as entered (a lower-case prefix names no
     * country). The prefix EL is Greece; MC is no VAT prefix, since
     * Monaco is in the EU VAT area through France.
     */
    private static function vatIsFromAnotherEuState(string $vat, string $merchantCountry): bool
    {
        $prefix = substr($vat, 0, 2);
        if ($prefix === self::VAT_PREFIX_GREECE) {
            $prefix = 'GR';
        }

        return $prefix !== 'MC' && $prefix !== $merchantCountry && in_array($prefix, self::EU, true);
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

        return self::FEE_KEY;
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

        $merchantCountry = $this->merchantCountry($storeId);
        $buyerCountry = $billing ? strtoupper(trim((string)$billing->getCountryId())) : '';
        $buyerPostcode = $billing ? (string)$billing->getPostcode() : '';
        $buyerVat = self::buyerVatNumber($order);
        $map = $this->configRepository->getTaxCodeMap($storeId);

        return [
            'store_id' => $storeId,
            'map' => $map,
            'merchant_country' => $merchantCountry,
            'derive' => $merchantCountry === 'ES',
            'dest_country' => $destination ? (string)$destination->getCountryId() : '',
            'dest_postcode' => $destination ? (string)$destination->getPostcode() : '',
            'buyer_country' => $buyerCountry,
            'buyer_postcode' => $buyerPostcode,
            'has_goods' => $hasGoods,
            'buyer_vat' => $buyerVat,
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
     * The line's product tax class, null for a line with none, and whether
     * derivation counts it as a service.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function lineClass(array $line, $item, array $context): array
    {
        if ($item !== null) {
            return [$this->productTaxClassId($item), self::isService($item)];
        }
        if (($line['type'] ?? '') === 'SHIPPING_FEE') {
            // Core's None is class 0, which the repository reports as null.
            return [$this->configRepository->getShippingTaxClassId($context['store_id']) ?? 0, !$context['has_goods']];
        }
        if (($line['order_item_id'] ?? null) === 'surcharge') {
            return [$this->configRepository->getSurchargeTaxClassId($context['store_id']), !$context['has_goods']];
        }
        if (in_array($line['type'] ?? '', self::PRODUCT_LINE_TYPES, true)) {
            // A product line no item matched: its own type still says goods or service.
            return [null, $line['type'] === 'DIGITAL'];
        }

        return [null, !$context['has_goods']];
    }

    /**
     * Steps 1 to 3 for a line with a product tax class, adding a code they
     * give to step 4's pool. Derivation only for a class with no row mapped.
     *
     * @param array<string, true> $shared step 4's pool
     */
    private function resolve(Order $order, int $classId, bool $isService, array $context, array &$shared): ?string
    {
        $row = $context['map'] !== [] ? $this->matchedRow($order, $classId, $context) : null;
        $code = $row !== null ? ($context['map'][$row] ?? null) : null;
        if ($code !== null) {
            $shared[$code] = true;
            return $code;
        }
        $prefix = $classId . '|';
        foreach (array_keys($context['map']) as $mapped) {
            if (strpos((string)$mapped, $prefix) === 0) {
                return null;
            }
        }

        return $context['derive'] ? $this->derived($isService, $context) : null;
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

    private function derived(bool $isService, array $context): ?string
    {
        return self::derive(
            $isService,
            $context['dest_country'],
            $context['dest_postcode'],
            $context['buyer_country'],
            $context['buyer_postcode'],
            $context['merchant_country'],
            $context['buyer_vat']
        );
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
