<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service;

use Exception;
use Magento\Bundle\Model\Product\Price;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollection;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo as CreditmemoModel;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\App\Emulation;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;

/**
 * Abstract order class
 */
abstract class Order
{
    /**
     * Ceiling on getOtherChargesLineItem()'s per-line-count epsilon. Bounds
     * the worst case of "a genuine small untaxed fee vanishes silently on
     * a large order" to this amount, regardless of how many line items the
     * order has. See that method's docblock.
     */
    private const OTHER_CHARGES_EPSILON_CEILING = 1.00;

    /**
     * Tolerance, in currency units, on tax == net * rate for a single line.
     * Same value and convention as the sibling plugins (TWO-25503): wide
     * enough for per-line 2dp rounding on either side of the equation,
     * narrow enough that a wrong rate or a wrong tax amount never passes.
     */
    private const TAX_FORMULA_TOLERANCE = 0.02;

    /**
     * @var ConfigRepository
     */
    public $configRepository;
    /**
     * @var Url
     */
    public $url;
    /**
     * @var CategoryCollection
     */
    private $categoryCollectionFactory;
    /**
     * @var Emulation
     */
    private $appEmulation;
    /**
     * @var Image
     */
    private $imageHelper;
    /**
     * @var OrderItemRepositoryInterface
     */
    private $orderItemRepository;
    /**
     * @var LogRepository
     */
    protected $logRepository;
    /**
     * @var FeeLineProviderPool
     */
    private $feeLineProviderPool;
    /**
     * @var OrderTaxManagementInterface
     */
    private $orderTaxManagement;

    /**
     * Order constructor.
     *
     * @param Image $imageHelper
     * @param ConfigRepository $configRepository
     * @param CategoryCollection $categoryCollectionFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param Emulation $appEmulation
     * @param Url $url
     * @param LogRepository $logRepository
     * @param FeeLineProviderPool $feeLineProviderPool
     * @param OrderTaxManagementInterface $orderTaxManagement
     */
    public function __construct(
        Image $imageHelper,
        ConfigRepository $configRepository,
        CategoryCollection $categoryCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        Emulation $appEmulation,
        Url $url,
        LogRepository $logRepository,
        FeeLineProviderPool $feeLineProviderPool,
        OrderTaxManagementInterface $orderTaxManagement
    ) {
        $this->imageHelper = $imageHelper;
        $this->configRepository = $configRepository;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->orderItemRepository = $orderItemRepository;
        $this->appEmulation = $appEmulation;
        $this->url = $url;
        $this->logRepository = $logRepository;
        $this->feeLineProviderPool = $feeLineProviderPool;
        $this->orderTaxManagement = $orderTaxManagement;
    }

    /**
     * Fee lines from registered FeeLineProviderInterface implementations
     * (see Api\Fee\FeeLineProviderInterface).
     *
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array
    {
        // A test double built via getMockForAbstractClass() with the
        // constructor skipped must inject a pool via reflection before
        // calling this — see Test/Unit/Service/Order/GetFeeLinesTest.php.
        return $this->feeLineProviderPool->getFeeLines($entity);
    }

    /**
     * Check if item should be skipped
     *
     * @param mixed $parentItem
     * @param mixed $item
     * @return bool
     */
    public function shouldSkip($parentItem, $item): bool
    {
        // Skip if bundle product with a dynamic price type
        if (Product\Type::TYPE_BUNDLE == $item->getProductType()
            && Price::PRICE_TYPE_DYNAMIC == $item->getProduct()->getPriceType()
        ) {
            return true;
        }

        if (!$parentItem) {
            return false;
        }

        // Skip if child product of a non bundle parent
        if (Product\Type::TYPE_BUNDLE != $parentItem->getProductType()) {
            return true;
        }

        // Skip if non bundle product or if bundled product with a fixed price type
        if (Product\Type::TYPE_BUNDLE != $parentItem->getProductType()
            || Price::PRICE_TYPE_FIXED == $parentItem->getProduct()->getPriceType()
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param OrderModel $order
     * @param OrderModel\Item $item
     * @return Product|null
     */
    public function getProduct(OrderModel $order, OrderModel\Item $item): ?Product
    {
        $product = $item->getProduct();
        $parentItem = $this->getParentItem($item, $order);
        if (!$product || $this->shouldSkip($parentItem, $item)) {
            return null;
        }

        return $parentItem ? $parentItem->getProduct() : $product;
    }

    /**
     * @param OrderModel\Item $item
     * @param OrderModel $order
     * @return OrderModel\Item|null
     */
    public function getParentItem(OrderModel\Item $item, OrderModel $order): ?OrderModel\Item
    {
        return $item->getParentItem()
            ?: ($item->getParentItemId() ? $order->getItemById($item->getParentItemId()) : null);
    }

    /**
     * @param int $itemId
     * @return OrderItemInterface
     */
    public function getOrderItem(int $itemId): OrderItemInterface
    {
        return $this->orderItemRepository->get($itemId);
    }

    /**
     * Get line items from order
     *
     * @param OrderModel $order
     * @return array
     * @throws LocalizedException
     */
    public function getLineItemsOrder(OrderModel $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (!$product = $this->getProduct($order, $item)) {
                continue;
            }
            $items[] = [
                'order_item_id' => $item->getId(),
                'name' => $item->getName(),
                'description' => $item->getName(),
                'type' => $item->getIsVirtual() ? 'DIGITAL' : 'PHYSICAL',
                'image_url' => $this->getProductImageUrl($product),
                'product_page_url' => $product->getProductUrl(),
                'gross_amount' => $this->roundAmt($this->getGrossAmountItem($item)),
                'net_amount' => $this->roundAmt($this->getNetAmountItem($item)),
                'tax_amount' => $this->roundAmt($this->getTaxAmountItem($item)),
                'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($item)),
                'tax_rate' => $this->roundAmt($this->getTaxRateItem($item), 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($item->getTaxPercent()) . '%',
                'unit_price' => $this->roundAmt($this->getUnitPriceItem($item), 6),
                'quantity' => $item->getQtyOrdered(),
                'qty_to_ship' => $item->getQtyToShip(), //need for partial shipment
                'quantity_unit' => $this->configRepository->getWeightUnit((int)$order->getStoreId()),
                'details' => [
                    'barcodes' => [
                        [
                            'type' => 'SKU',
                            'value' => $item->getSku(),
                        ],
                    ],
                    'categories' => $this->getCategories($product->getCategoryIds()),
                ]
            ];
        }

        if (!$order->getIsVirtual() && $order->getShippingAmount() > 0) {
            $items[] = $this->getShippingLineOrder($order);
        }

        return $items;
    }

    /**
     * Get product image
     *
     * @param Product $product
     * @return string
     */
    public function getProductImageUrl(Product $product): string
    {
        try {
            $this->appEmulation->startEnvironmentEmulation($product->getStoreId(), Area::AREA_FRONTEND, true);
            return $this->imageHelper->init($product, 'product_small_image')->getUrl();
        } catch (Exception $exception) {
            return '';
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Format price
     *
     * @param mixed $amt
     * @param int $dp
     *
     * @return string
     */
    public function roundAmt($amt, $dp = 2): string
    {
        return number_format((float)$amt, $dp, '.', '');
    }

    /**
     * Get gross amount (inclusive of tax)
     *
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     */
    public function getGrossAmountItem($item): float
    {
        return $this->getNetAmountItem($item) + $this->getTaxAmountItem($item);
    }

    /**
     *  Get net amount (inclusive of discount)
     *
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     * @return float
     */
    public function getNetAmountItem($item): float
    {
        return (float)$item->getRowTotal() - $this->getDiscountAmountItem($item);
    }

    /**
     * Get unit price
     *
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     */
    public function getUnitPriceItem($item): float
    {
        // $item->getRowTotal() is before discount
        return (float)$item->getRowTotal() / (float)$item->getQtyOrdered();
    }

    /**
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     */
    public function getTaxRateItem($item): float
    {
        return (float)$item->getTaxPercent() / 100;
    }

    /**
     * Get tax amount inclusive of discount
     *
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     */
    public function getTaxAmountItem($item): float
    {
        return (float)$item->getTaxAmount();
    }

    /**
     * Get discount amount before tax
     *
     * Fails loud (log + throw) on a genuinely negative discount instead of
     * letting a bad value from an upstream cart-rule bug flow silently into
     * the Two API payload. Never clamps.
     *
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     * @throws LocalizedException when the discount is negative at currency precision
     */
    public function getDiscountAmountItem($item): float
    {
        // Compute at native float precision — never round the inputs first.
        // Early per-component rounding is the phantom-negative trap
        // (TWO-24741). The returned value stays native so the payload
        // boundary keeps its single roundAmt() call.
        $discountAmount = (float)$item->getDiscountAmount()
            - (float)$item->getDiscountTaxCompensationAmount();

        // Sign-check at the currency precision the payload will actually
        // send: sub-cent float residue is not a data error; a discount that
        // is still negative after the boundary round is.
        if (round($discountAmount, 2) < 0) {
            $message = sprintf(
                'Negative discount amount %.6F for order item %s (sku %s): '
                . 'discount %.6F - discount tax compensation %.6F',
                $discountAmount,
                $item->getId(),
                $item->getSku(),
                (float)$item->getDiscountAmount(),
                (float)$item->getDiscountTaxCompensationAmount()
            );
            $this->logRepository->addErrorLog('NegativeDiscountGuard', $message);
            throw new LocalizedException(__($message));
        }

        return $discountAmount;
    }

    /**
     * Get category array by category ids
     *
     * @param array $categoryIds
     *
     * @return array
     * @throws LocalizedException
     */
    public function getCategories(array $categoryIds): array
    {
        $categories = [];
        if (!$categoryIds) {
            return $categories;
        }

        foreach ($this->getCategoryCollection($categoryIds) as $category) {
            $categories[] = $category->getName();
        }

        return $categories;
    }

    /**
     * Get category collection
     *
     * @param array $categoryIds
     *
     * @return Collection
     */
    private function getCategoryCollection($categoryIds): Collection
    {
        return $this->categoryCollectionFactory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('entity_id', $categoryIds)
            ->addIsActiveFilter();
    }

    /**
     * @param OrderModel $order
     * @return array
     */
    public function getShippingLineOrder(OrderModel $order): array
    {
        $taxRate = $this->getTaxRateShipping($order);

        return [
            'order_item_id' => 'shipping',
            'name' => 'Shipping - ' . $order->getShippingDescription(),
            'description' => '',
            'type' => 'SHIPPING_FEE',
            'image_url' => '',
            'product_page_url' => '',
            'gross_amount' => $this->roundAmt($this->getGrossAmountShipping($order)),
            'net_amount' => $this->roundAmt($this->getNetAmountShipping($order)),
            'tax_amount' => $this->roundAmt($this->getTaxAmountShipping($order)),
            'discount_amount' => $this->roundAmt($this->getDiscountAmountShipping($order)),
            'tax_rate' => $this->roundAmt($taxRate, 6),
            'unit_price' => $this->roundAmt($this->getUnitPriceShipping($order), 6),
            'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
            'quantity' => 1,
            'qty_to_ship' => 1, //need for partial shipment
            'quantity_unit' => 'sc',
        ];
    }

    /**
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getGrossAmountShipping($entity): float
    {
        return (float)($this->getNetAmountShipping($entity) + $this->getTaxAmountShipping($entity));
    }

    /**
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getNetAmountShipping($entity): float
    {
        return (float)(
            $this->getUnitPriceShipping($entity) - $this->getDiscountAmountShipping($entity)
        );
    }

    /**
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getUnitPriceShipping($entity): float
    {
        return (float)$entity->getShippingAmount();
    }

    /**
     * Get shipping discount amount before tax
     *
     * Fails loud (log + throw) on a genuinely negative shipping discount —
     * same guard as getDiscountAmountItem(), parallel surface.
     *
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     * @throws LocalizedException when the discount is negative at currency precision
     */
    public function getDiscountAmountShipping($entity): float
    {
        // Native-precision compute, single round at the payload boundary —
        // see getDiscountAmountItem() for the rounding-order rationale.
        $discountAmount = (float)$entity->getShippingDiscountAmount()
            - (float)$entity->getShippingDiscountTaxCompensationAmount();

        if (round($discountAmount, 2) < 0) {
            $message = sprintf(
                'Negative shipping discount amount %.6F for entity %s: '
                . 'shipping discount %.6F - shipping discount tax compensation %.6F',
                $discountAmount,
                $entity->getIncrementId(),
                (float)$entity->getShippingDiscountAmount(),
                (float)$entity->getShippingDiscountTaxCompensationAmount()
            );
            $this->logRepository->addErrorLog('NegativeDiscountGuard', $message);
            throw new LocalizedException(__($message));
        }

        return $discountAmount;
    }

    /**
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getTaxAmountShipping($entity): float
    {
        return (float)($entity->getShippingTaxAmount());
    }

    /**
     * Shipping tax rate as a fraction, relayed from Magento's tax engine.
     *
     * Never derived from the amounts (TWO-25503). A rate computed as
     * tax/net is a different statement from the rate the store actually
     * applied: rounding, mixed rates and a discounted shipping base all
     * make the quotient land somewhere no tax rule declares, and Two
     * validates the declared rate against the line's own numbers.
     *
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     * @throws LocalizedException when shipping is taxed, no rate is declared
     *                            and the merchant configured no fallback
     */
    public function getTaxRateShipping($entity): float
    {
        $declaredPercent = $this->getDeclaredShippingTaxPercent($entity);
        if ($declaredPercent !== null) {
            return $declaredPercent / 100;
        }

        // A shipping line carrying no tax needs no declaration: 0% is a
        // statement, not a guess. This is also the ordinary shape of a
        // store whose shipping is not taxed at all — no tax rule applied
        // means no rate to look up.
        if (round($this->getTaxAmountShipping($entity), 2) === 0.0) {
            return 0.0;
        }

        $storeId = (int)$entity->getStoreId();
        $fallbackPercent = $this->configRepository->getDefaultShippingTaxRate($storeId);
        if ($fallbackPercent === null) {
            $this->logRepository->addErrorLog(
                'ShippingTaxRateUnresolvable',
                sprintf(
                    'Shipping is taxed (%.2F) on entity %s but Magento declares no rate for it, '
                    . 'and no default shipping tax rate is configured. Refusing rather than deriving a rate.',
                    $this->getTaxAmountShipping($entity),
                    $entity->getIncrementId()
                )
            );
            throw new LocalizedException(
                __('This order could not be placed. Please contact the merchant.')
            );
        }

        $this->logRepository->addDebugLog(
            'ShippingTaxRateFallback',
            sprintf(
                'Using the configured default shipping tax rate %.6F%% for entity %s: '
                . 'Magento declared no rate for a taxed shipping line.',
                $fallbackPercent,
                $entity->getIncrementId()
            )
        );

        return $fallbackPercent / 100;
    }

    /**
     * The shipping tax percentage Magento's own tax engine recorded for the
     * order, or NULL when it recorded none. Summed across applied taxes so a
     * combined rate (e.g. state + city) reports as the single rate the buyer
     * was charged, matching how getTaxRateItem() reads tax_percent.
     *
     * @param OrderModel|CreditmemoModel $entity
     * @return float|null
     */
    private function getDeclaredShippingTaxPercent($entity): ?float
    {
        // A creditmemo/invoice relays its parent order's declared rate —
        // a refund does not re-derive tax.
        $orderId = method_exists($entity, 'getOrder') && $entity->getOrder()
            ? (int)$entity->getOrder()->getId()
            : (int)$entity->getId();
        if ($orderId <= 0) {
            return null;
        }

        try {
            $details = $this->orderTaxManagement->getOrderTaxDetails($orderId);
        } catch (Exception $exception) {
            // No tax record for this order (or the read failed): treated as
            // "nothing declared" so the caller's fallback/refuse path owns
            // the decision rather than this method inventing a rate.
            return null;
        }

        $percent = null;
        foreach ($details->getItems() ?? [] as $taxItem) {
            if ($taxItem->getType() !== CommonTaxCollector::ITEM_TYPE_SHIPPING) {
                continue;
            }
            foreach ($taxItem->getAppliedTaxes() ?? [] as $appliedTax) {
                $percent = ($percent ?? 0.0) + (float)$appliedTax->getPercent();
            }
        }

        return $percent;
    }

    /**
     * Refuse any line whose declared tax does not reconcile with its own
     * declared rate and net amount (TWO-25503).
     *
     * The plugin never derives a rate from amounts and never corrects a
     * line's numbers, so an internally-inconsistent line has to stop the
     * checkout: the buyer sees a generic notice and the detail goes to the
     * log. Tolerance is in currency units, the same convention as
     * getOtherChargesLineItem()'s epsilon.
     *
     * @param array $lineItems Composed payload line items.
     * @return void
     * @throws LocalizedException when a line's tax does not reconcile
     */
    public function validateTaxReconciliation(array $lineItems): void
    {
        foreach ($lineItems as $lineItem) {
            $net = (float)($lineItem['net_amount'] ?? 0);
            $tax = (float)($lineItem['tax_amount'] ?? 0);
            $rate = (float)($lineItem['tax_rate'] ?? 0);

            $discrepancy = abs($tax - $net * $rate);
            if ($discrepancy <= self::TAX_FORMULA_TOLERANCE) {
                continue;
            }

            $this->logRepository->addErrorLog(
                'TaxReconciliationFailed',
                sprintf(
                    'Line %s declares tax %.2F but rate %.6F on net %.2F implies %.2F '
                    . '(off by %.2F, tolerance %.2F).',
                    $lineItem['order_item_id'] ?? 'unknown',
                    $tax,
                    $rate,
                    $net,
                    $net * $rate,
                    $discrepancy,
                    self::TAX_FORMULA_TOLERANCE
                )
            );
            throw new LocalizedException(
                __('This order could not be placed. Please contact the merchant.')
            );
        }
    }

    /**
     * @param OrderModel $order
     * @param array|null $additionalData
     * @param string $type
     * @return array
     */
    public function getAddress(OrderModel $order, ?array $additionalData, string $type): array
    {
        $address = $type === 'billing'
            ? $order->getBillingAddress()
            : $order->getShippingAddress();

        // For virtual orders requesting shipping, use billing address instead
        if ($type !== 'billing' && $order->getIsVirtual()) {
            $address = $order->getBillingAddress();
        }

        // Basic safety check
        if (!$address) {
            $address = $order->getBillingAddress();
        }

        return [
            'city' => $address->getCity(),
            'country' => $address->getCountryId(),
            'organization_name' => !empty($additionalData['companyName'])
                ? $additionalData['companyName']
                : $address->getCompany(),
            'postal_code' => $address->getPostcode(),
            'region' => $address->getRegion() != '' ? $address->getRegion() : '',
            'street_address' => $address->getStreet()[0]
                . (isset($address->getStreet()[1]) ? ', ' . $address->getStreet()[1] : ''),
        ];
    }

    /**
     * @param OrderModel $order
     * @param array|null $additionalData
     * @return array[]
     */
    public function getBuyer(OrderModel $order, ?array $additionalData): array
    {
        $billingAddress = $order->getBillingAddress();

        return [
            'representative' => [
                'email' => $billingAddress->getEmail(),
                'first_name' => $billingAddress->getFirstName(),
                'last_name' => $billingAddress->getLastName(),
                'phone_number' => $billingAddress->getTelephone(),
            ],
            'company' => [
                'organization_number' => $additionalData['companyId'] ?? '',
                'country_prefix' => $billingAddress->getCountryId(),
                'company_name' => !empty($additionalData['companyName'])
                    ? $additionalData['companyName']
                    : $billingAddress->getCompany(),
            ]
        ];
    }

    /**
     * SECONDARY fallback for any total-collector amount that inflates
     * grand_total without being itemized elsewhere.
     *
     * Magento only lets us itemize what we know about: product items,
     * shipping, our own surcharge. A third-party extension that adds a
     * fee the same way Magento adds shipping — a totals-collector amount
     * bumping grand_total, but not a quote/order item (e.g. Amasty's
     * "Extra Fee" module) — is invisible to every getLineItems*() method
     * above, while still being included in the aggregate total we report.
     * That leaves sum(line_items) != grand_total.
     *
     * The PRIMARY mechanism is a registered FeeLineProviderInterface
     * (see getFeeLines() / Api\Fee\FeeLineProviderInterface): a provider
     * that knows a specific fee's real per-fee tax rate directly (e.g. by
     * reading a vendor's own table). Callers are expected to merge
     * getFeeLines() into $lineItems before calling this method, so this
     * only ever has to reconcile what no provider recognized.
     *
     * The SECONDARY mechanism, for a residual WITH tax that no provider
     * claimed, is findVerifiedResidualTaxRate(): rather than guess a rate,
     * it checks whether Magento's own tax engine already vouches for one
     * (see that method's docblock). This covers any well-behaved
     * total-collector extension without needing a per-vendor provider —
     * only an extension that computes its own tax outside Magento's tax
     * engine still needs a FeeLineProviderInterface.
     *
     * Only once BOTH of those come up empty do we fall back further: a
     * synthetic line is auto-emitted when the residual is genuinely
     * untaxed (residual tax rounds to zero — a 0% line is always a valid
     * statement, never a guess). Any remaining residual with a real,
     * unverifiable non-zero tax component is a fee we can't safely
     * itemize blind: submitting a blended/guessed tax_rate risks Two's
     * API rejecting the payload (or worse, silently accepting a wrong VAT
     * rate), so instead this logs a loud warning and leaves it
     * unreconciled — visibility over a guess.
     *
     * Returns null when there's nothing to reconcile (or the residual
     * can't be safely reconciled), so an ordinary order never gets a
     * synthetic line.
     *
     * Two more guards, both there to stop this firing on completely
     * ordinary orders that have nothing to do with a third-party fee:
     *
     *  - The epsilon scales with $lineItems' count (min 0.01, +0.005 per
     *    line, capped at self::OTHER_CHARGES_EPSILON_CEILING). Every
     *    gross_amount here is independently roundAmt()'d to 2dp; summing N
     *    independently-rounded values can legitimately drift from the
     *    entity's own higher-precision aggregate column by up to ~N*0.005
     *    (the same bound ComposeRefund's own line-summing comment already
     *    documents) with zero third-party extension involved. A flat
     *    1-cent epsilon would false-positive on any large multi-item
     *    order — but leaving it uncapped would let a genuine small
     *    untaxed fee vanish silently (no log at all — this is the
     *    "ordinary rounding noise" branch, deliberately quiet) on a large
     *    enough order. The ceiling bounds that worst case.
     *  - Only a POSITIVE residual (grand_total > known items) is ever
     *    auto-emitted. A negative residual means known items already
     *    exceed grand_total, which isn't a "fee we forgot" — it's more
     *    likely a bug in our own line-item math (e.g. double-counting)
     *    than a legitimate negative-amount fee. Paper over it with a
     *    synthetic line and the real bug goes undiagnosed; log it instead.
     *
     * @param array $lineItems Line items already built for this entity
     *                          (products, shipping, surcharge, entity-native
     *                          adjustment lines, and any FeeLineProviderInterface
     *                          output already merged in).
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @param float $grandTotal The entity's own aggregate gross/grand total.
     * @param float $taxTotal The entity's own aggregate tax total.
     * @return array|null
     */
    public function getOtherChargesLineItem(array $lineItems, $entity, float $grandTotal, float $taxTotal): ?array
    {
        $knownGross = 0.0;
        $knownTax = 0.0;
        foreach ($lineItems as $lineItem) {
            $knownGross += (float)($lineItem['gross_amount'] ?? 0);
            $knownTax += (float)($lineItem['tax_amount'] ?? 0);
        }

        $epsilon = min(max(0.01, 0.005 * count($lineItems)), self::OTHER_CHARGES_EPSILON_CEILING);

        $residualGross = round($grandTotal - $knownGross, 2);
        if (abs($residualGross) <= $epsilon) {
            // Ordinary rounding noise, not an untracked total.
            return null;
        }

        if ($residualGross < 0) {
            // Known items already exceed grand_total. Not a fee we
            // forgot — more likely our own line-item math double-counted
            // something. Surface it; don't invent a negative-amount line
            // to paper over a bug that needs diagnosing.
            $this->logRepository->addErrorLog(
                'UnreconciledOtherCharges',
                sprintf(
                    'sum(line_items.gross_amount) exceeds grand_total by %.2F. '
                    . 'Not auto-itemizing a negative correction line.',
                    abs($residualGross)
                )
            );
            return null;
        }

        $residualTax = round($taxTotal - $knownTax, 2);
        if (abs($residualTax) > $epsilon) {
            $residualNet = round($residualGross - $residualTax, 2);
            $verifiedRate = $this->findVerifiedResidualTaxRate($entity, $residualNet, $residualTax, $epsilon);
            if ($verifiedRate !== null) {
                return [
                    'order_item_id' => 'other_charges',
                    'name' => (string)__('Other charges'),
                    'description' => (string)__('Other charges'),
                    'type' => 'OTHER',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => $this->roundAmt($residualGross),
                    'net_amount' => $this->roundAmt($residualNet),
                    'tax_amount' => $this->roundAmt($residualTax),
                    'discount_amount' => '0.00',
                    'tax_rate' => $this->roundAmt($verifiedRate / 100, 6),
                    'tax_class_name' => 'VAT ' . $this->roundAmt($verifiedRate) . '%',
                    'unit_price' => $this->roundAmt($residualNet, 6),
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ];
            }

            // Non-zero tax on an unrecognized residual, and no rate
            // Magento's own tax engine vouches for reconciles it either:
            // we don't know its real rate. Guessing one is worse than not
            // reconciling — surface it loudly instead so it can be
            // diagnosed and, if it recurs, given a real
            // FeeLineProviderInterface.
            $this->logRepository->addErrorLog(
                'UnreconciledOtherCharges',
                sprintf(
                    'grand_total exceeds sum(line_items) by %.2F with a non-zero tax '
                    . 'component (%.2F) that no FeeLineProviderInterface recognized and '
                    . 'no applied tax rate on the order reconciles. '
                    . 'Not auto-itemizing an unverified tax rate.',
                    $residualGross,
                    $residualTax
                )
            );
            return null;
        }

        // Residual tax is zero (or rounds to it) — a 0% line is always a
        // valid, honest statement, safe to auto-emit without a provider.
        $residualNet = $residualGross;

        return [
            'order_item_id' => 'other_charges',
            'name' => (string)__('Other charges'),
            'description' => (string)__('Other charges'),
            'type' => 'OTHER',
            'image_url' => '',
            'product_page_url' => '',
            'gross_amount' => $this->roundAmt($residualGross),
            'net_amount' => $this->roundAmt($residualNet),
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'tax_rate' => '0.000000',
            'tax_class_name' => 'VAT 0%',
            'unit_price' => $this->roundAmt($residualNet, 6),
            'quantity' => 1,
            'quantity_unit' => 'sc',
        ];
    }

    /**
     * Looks for a genuine, Magento-verified tax rate that reconciles a
     * taxed residual, so a taxed residual doesn't have to be either a
     * registered FeeLineProviderInterface's job or thrown away
     * unreconciled.
     *
     * Any total-collector extension that correctly integrates with
     * Magento's tax engine — Magento's own Weee, or a well-built
     * third-party fee module — registers its tax via the same
     * `applied_taxes` quote-address total data Magento's own
     * product/shipping tax uses. Magento copies that onto the order's own
     * extension attributes during quote-to-order conversion
     * (Magento\Tax\Model\Quote\ToOrderConverter::afterConvert(), a plugin
     * on Quote\Address\ToOrder::convert()) — which runs during
     * QuoteManagement::submitQuote(), well before Order::place() calls
     * authorize(). So it's already sitting on the in-memory $entity this
     * class is handed, with no entity_id needed and no vendor coupling:
     * if a rate Magento itself applied would produce exactly this
     * residual's tax on this residual's net amount, that rate is real,
     * not invented.
     *
     * Only Order carries this extension attribute (populated from the
     * quote it was converted from) — Invoice/Creditmemo don't, so this
     * can't help reconcile a residual on those entities.
     *
     * Each applied-tax entry's shape depends on exactly when it's read:
     * right after ToOrderConverter::afterConvert() it's a plain array
     * (that's all the plugin sets), but QuoteManagement::submitQuote()
     * immediately re-merges the converted order into a fresh one via
     * DataObjectHelper::mergeDataObjects() — which rehydrates the array
     * into Magento\Tax\Model\Sales\Order\Tax objects (confirmed live:
     * this is what the $entity ComposeOrder actually receives carries).
     * Handling both shapes isn't defensive padding for a case that can't
     * happen — both cases are real, just at different points in the same
     * conversion.
     *
     * Matches on amount, not identity: the first applied rate whose
     * implied tax reconciles the residual wins, with no proof that rate
     * specifically produced this residual rather than some other taxable
     * amount on the order. On an order with several distinct tax classes
     * this is a real (if narrow) way to attribute the wrong tax_rate/
     * tax_class_name to the residual — two rates would have to coincide
     * with the same net/tax split for that to happen. The emitted
     * gross/net/tax amounts stay correct either way; only the reported
     * rate label could be wrong.
     *
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @return float|null The verified rate as a percent (e.g. 20.0), or
     *                     null if no applied rate reconciles the residual.
     */
    private function findVerifiedResidualTaxRate($entity, float $residualNet, float $residualTax, float $epsilon): ?float
    {
        if (!$entity instanceof OrderModel) {
            return null;
        }

        $extensionAttributes = $entity->getExtensionAttributes();
        $appliedTaxes = $extensionAttributes ? $extensionAttributes->getAppliedTaxes() : null;
        if (!$appliedTaxes) {
            return null;
        }

        foreach ($appliedTaxes as $appliedTax) {
            if (is_array($appliedTax)) {
                $percent = $appliedTax['percent'] ?? null;
            } elseif (is_object($appliedTax) && method_exists($appliedTax, 'getPercent')) {
                $percent = $appliedTax->getPercent();
            } else {
                $percent = null;
            }
            if (!$percent) {
                continue;
            }

            $impliedTax = round($residualNet * (float)$percent / 100, 2);
            if (abs($impliedTax - $residualTax) <= $epsilon) {
                return (float)$percent;
            }
        }

        return null;
    }

    /**
     * Shared glue for ComposeOrder/ComposeCapture/ComposeRefund: merge any
     * registered FeeLineProviderInterface output into $lineItems, then
     * append the getOtherChargesLineItem() fallback if it has anything to
     * reconcile. One call site instead of three near-identical ones, so a
     * future change to the merge order/logic only needs to happen once.
     *
     * @param array $lineItems Line items already built for this entity.
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @param float $grandTotal The entity's own aggregate gross/grand total.
     * @param float $taxTotal The entity's own aggregate tax total.
     * @return array $lineItems with fee-provider output and/or the residual
     *               fallback appended.
     */
    protected function reconcileOtherCharges(array $lineItems, $entity, float $grandTotal, float $taxTotal): array
    {
        foreach ($this->getFeeLines($entity) as $feeLine) {
            $lineItems[] = $feeLine;
        }

        $otherCharges = $this->getOtherChargesLineItem($lineItems, $entity, $grandTotal, $taxTotal);
        if ($otherCharges) {
            $lineItems[] = $otherCharges;
        }

        return $lineItems;
    }

    /**
     * @param array $lineItems
     * @return array
     */
    public function getTaxSubtotals(array $lineItems): ?array
    {
        if (!$this->configRepository->isTaxSubtotalsEnabled()) {
            return null;
        }
        $taxSubtotals = [];
        foreach ($lineItems as $lineItem) {
            $taxSubtotals[$lineItem['tax_rate']][] = [
                'taxable_amount' => $lineItem['net_amount'],
                'tax_amount' => $lineItem['tax_amount'],

            ];
        }

        $summary = [];
        foreach ($taxSubtotals as $taxRate => $amounts) {
            $taxableAmount = $this->getSum($amounts, 'taxable_amount');
            $taxAmount = $this->getSum($amounts, 'tax_amount');
            $summary[] = [
                'taxable_amount' => $this->roundAmt($taxableAmount),
                'tax_amount' => $this->roundAmt($taxAmount),
                'tax_rate' => $this->roundAmt($taxRate, 6)
            ];
        }

        return $summary;
    }

    /**
     * @param $itemsArray
     * @param $columnKey
     * @return string
     */
    public function getSum($itemsArray, $columnKey): string
    {
        return $this->roundAmt(
            array_sum(array_column($itemsArray, $columnKey))
        );
    }
}
