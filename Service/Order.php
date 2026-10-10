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
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
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
use Magento\Tax\Model\Calculation as TaxCalculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory as OrderTaxCollectionFactory;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order\TaxCodeResolver;

/**
 * Abstract order class
 */
abstract class Order
{
    /** two_shipping_tax_rate_source: Magento recorded a shipping rate at placement, 0% included. */
    public const SHIPPING_RATE_DECLARED = 'declared';

    /** two_shipping_tax_rate_source: Magento recorded no shipping rate at placement. */
    public const SHIPPING_RATE_NONE = 'none';

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
     * @var TaxCalculation
     */
    private $taxCalculation;

    /**
     * @var OrderTaxCollectionFactory
     */
    private $orderTaxCollectionFactory;

    /**
     * @var GroupRepositoryInterface
     */
    private $groupRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @var TaxCodeResolver|null
     */
    private $taxCodeResolver;

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
     * @param TaxCalculation $taxCalculation
     * @param OrderTaxCollectionFactory $orderTaxCollectionFactory
     * @param GroupRepositoryInterface $groupRepository
     * @param BrandRegistryInterface $brandRegistry
     * @param CustomerRepositoryInterface $customerRepository
     * @param TaxCodeResolver|null $taxCodeResolver
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
        OrderTaxManagementInterface $orderTaxManagement,
        TaxCalculation $taxCalculation,
        OrderTaxCollectionFactory $orderTaxCollectionFactory,
        GroupRepositoryInterface $groupRepository,
        BrandRegistryInterface $brandRegistry,
        CustomerRepositoryInterface $customerRepository,
        ?TaxCodeResolver $taxCodeResolver = null
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
        $this->taxCalculation = $taxCalculation;
        $this->orderTaxCollectionFactory = $orderTaxCollectionFactory;
        $this->groupRepository = $groupRepository;
        $this->brandRegistry = $brandRegistry;
        $this->customerRepository = $customerRepository;
        $this->taxCodeResolver = $taxCodeResolver;
    }

    /**
     * The lines with a Two tax code on each 0% line that resolves one
     * (TWO-24877). Every request that carries lines calls this before the
     * postprocessing hook, so a subscriber can still change the code.
     *
     * @param array $lineItems
     * @param OrderModel $order
     * @param bool $orderLines true when $lineItems starts with getLineItemsOrder()'s product
     *                         lines, whose items have no id yet at placement
     * @return array
     */
    public function applyTaxCodes(array $lineItems, OrderModel $order, bool $orderLines = false): array
    {
        // Null only where a test skipped the constructor; etc/di.xml names it.
        if (!$this->taxCodeResolver) {
            return $lineItems;
        }
        $items = $orderLines ? $this->matchLineItemSources($lineItems, $order) : [];

        return $this->taxCodeResolver->apply($lineItems, $order, $items);
    }

    /**
     * The item behind each product line, by line key. Matched on SKU rather
     * than position, so a plugin on getLineItemsOrder() that drops, reorders or
     * adds lines cannot hand a line another item's class; the name only picks
     * between items sharing a SKU. A line whose SKU a plugin changed matches
     * nothing, keeps its goods or service type, and resolves afresh later.
     *
     * @param array $lineItems
     * @param OrderModel $order
     * @return array<int|string, OrderModel\Item>
     */
    private function matchLineItemSources(array $lineItems, OrderModel $order): array
    {
        $unused = array_column($this->getLineItemSourcesOrder($order), 0);
        $matched = [];
        foreach ($lineItems as $key => $line) {
            $sku = $line['details']['barcodes'][0]['value'] ?? null;
            $candidates = array_filter($unused, static fn ($item) => $sku !== null && $item->getSku() === $sku);
            if ($candidates === []) {
                continue;
            }
            $named = array_filter($candidates, static fn ($item) => $item->getName() === ($line['name'] ?? null));
            $i = array_key_first($named ?: $candidates);
            $matched[$key] = $unused[$i];
            unset($unused[$i]);
        }

        return $matched;
    }

    /**
     * The order items getLineItemsOrder() turns into lines, in the same order,
     * each with the product its line describes.
     *
     * @param OrderModel $order
     * @return array<int, array{0: OrderModel\Item, 1: Product}>
     */
    public function getLineItemSourcesOrder(OrderModel $order): array
    {
        $sources = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ($product = $this->getProduct($order, $item)) {
                $sources[] = [$item, $product];
            }
        }

        return $sources;
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
        foreach ($this->getLineItemSourcesOrder($order) as [$item, $product]) {
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
     * Get the order's total discount before tax, as a positive amount
     *
     * Magento stores the order-level discount negative, unlike the item and
     * shipping discounts, which it stores positive (TWO-26277). The absolute
     * value is sent, less the tax compensation, the same convention as the
     * line discounts it totals. The field is informational, so its sign never
     * refuses an order: a value below zero is sent as 0. The line discounts
     * keep their guard (TWO-25099).
     *
     * @param OrderModel $order
     * @return float
     */
    public function getDiscountAmountOrder(OrderModel $order): float
    {
        // Native-precision compute, single round at the payload boundary:
        // see getDiscountAmountItem() for the rounding-order rationale.
        $discountAmount = abs((float)$order->getDiscountAmount())
            - (float)$order->getDiscountTaxCompensationAmount();

        return round($discountAmount, 2) < 0 ? 0.0 : $discountAmount;
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
        // The fallback reconcile runs after the postprocessing hook (TWO-26276).
        $taxRate = $this->getTaxRateShipping($order, false);

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
     * Shipping tax rate as a fraction (TWO-26117).
     *
     * A rate Magento's tax engine recorded for the shipping line, 0% included,
     * is sent as is: never derived from the amounts (TWO-25503) and not
     * checked here, Two's API validates it. Without a recorded rate the line
     * is sent at 0% with its tax as charged, unless the store's shipping tax
     * fallback is populated: then the rate comes from core's Tax Class for
     * Shipping and the line's tax has to reconcile with it.
     *
     * Placement records which case applied, and the fallback's rate, on the
     * order: Magento does not save a 0% shipping rate with the order, so its
     * tax records alone cannot tell the two cases apart afterwards. Later
     * requests read that record and never the current configuration. An order
     * placed before it was recorded resolves as at placement.
     *
     * The builders pass $reconcile false: the reconcile is a shop-match check,
     * which the order postprocessing default handler runs after the hook
     * through assertShippingTaxFallback() (TWO-26276).
     *
     * @param OrderModel|\Magento\Sales\Model\Order\Invoice|CreditmemoModel $entity
     * @param bool $reconcile false to skip the fallback reconcile: a builder, or a refund, which relays its
     *                        parent line's rate
     * @return float
     * @throws LocalizedException when the fallback rate does not reconcile with the line's tax
     */
    public function getTaxRateShipping($entity, bool $reconcile = true): float
    {
        $order = $this->shippingTaxRateOrder($entity);
        $recorded = $this->recordedShippingTaxRate($order);
        if ($recorded !== null) {
            [$source, $rate] = $recorded;
            if ($source === self::SHIPPING_RATE_NONE && $rate !== null && $reconcile) {
                $this->assertShippingTaxReconciles($entity, $rate);
            }
            return $rate ?? 0.0;
        }

        [$source, $rate] = $this->resolveShippingTaxRate($entity, $reconcile);
        if (method_exists($order, 'getData') && !$order->getId()) {
            $order->setData('two_shipping_tax_rate_source', $source);
            $order->setData('two_shipping_tax_rate', $rate === null ? null : round($rate * 100, 6));
        }
        return $rate ?? 0.0;
    }

    /**
     * The shipping tax fallback reconcile on its own, for the entity the
     * shipping line was composed from: a shop-match check (TWO-26276). Reads
     * the case the build recorded, so it checks exactly what the builder
     * would have; refuses as getTaxRateShipping() does, and does nothing
     * where the fallback did not supply the rate.
     *
     * @param OrderModel|\Magento\Sales\Model\Order\Invoice $entity
     * @return void
     * @throws ShopMatchRefusedException when the fallback rate does not reconcile with the line's tax
     */
    public function assertShippingTaxFallback($entity): void
    {
        $recorded = $this->recordedShippingTaxRate($this->shippingTaxRateOrder($entity));
        if ($recorded === null) {
            $this->resolveShippingTaxRate($entity, true, false);
            return;
        }
        [$source, $rate] = $recorded;
        if ($source === self::SHIPPING_RATE_NONE && $rate !== null) {
            $this->assertShippingTaxReconciles($entity, $rate);
        }
    }

    /**
     * @param OrderModel|\Magento\Sales\Model\Order\Invoice|CreditmemoModel $entity
     * @return OrderModel|\Magento\Sales\Model\Order\Invoice|CreditmemoModel the order carrying the record
     */
    private function shippingTaxRateOrder($entity)
    {
        return method_exists($entity, 'getOrder') && $entity->getOrder() ? $entity->getOrder() : $entity;
    }

    /**
     * The case placement recorded, and its rate as a fraction; null when nothing was recorded.
     *
     * @param mixed $order
     * @return array{0: string, 1: float|null}|null
     */
    private function recordedShippingTaxRate($order): ?array
    {
        $source = method_exists($order, 'getData') ? $order->getData('two_shipping_tax_rate_source') : null;
        if ($source !== self::SHIPPING_RATE_DECLARED && $source !== self::SHIPPING_RATE_NONE) {
            return null;
        }
        $percent = $order->getData('two_shipping_tax_rate');

        return [$source, $percent === null ? null : (float)$percent / 100];
    }

    /**
     * The table as the current order data and configuration answer it: which
     * case applies, and the rate, null for no recorded rate and a blank fallback.
     *
     * @param OrderModel|\Magento\Sales\Model\Order\Invoice|CreditmemoModel $entity
     * @param bool $reconcile
     * @param bool $log false to resolve again without a second debug line
     * @return array{0: string, 1: float|null}
     * @throws LocalizedException
     */
    private function resolveShippingTaxRate($entity, bool $reconcile, bool $log = true): array
    {
        $declaredPercent = $this->getDeclaredShippingTaxPercent($entity);
        if ($declaredPercent !== null) {
            return [self::SHIPPING_RATE_DECLARED, $declaredPercent / 100];
        }

        $storeId = (int)$entity->getStoreId();
        $taxClassId = $this->configRepository->isShippingTaxFallbackEnabled($storeId)
            ? $this->configRepository->getShippingTaxClassId($storeId)
            : null;
        if ($taxClassId === null) {
            return [self::SHIPPING_RATE_NONE, null];
        }

        $rate = $this->resolveShippingTaxRateForClass($taxClassId, $entity, $storeId);
        if ($reconcile) {
            $this->assertShippingTaxReconciles($entity, $rate);
        }
        if ($log) {
            $this->logRepository->addDebugLog(
                'ShippingTaxRateFallback',
                sprintf(
                    'Using the tax-rules-engine rate %.6F%% for entity %s (shipping tax class %d): '
                    . 'Magento recorded no rate for the shipping line.',
                    $rate * 100,
                    $entity->getIncrementId(),
                    $taxClassId
                )
            );
        }
        return [self::SHIPPING_RATE_NONE, $rate];
    }

    /**
     * The fallback rate must account for the tax Magento charged on shipping,
     * on the discounted base or, under "Before Discount", the undiscounted one.
     * An invoice books the order's shipping discount, as ComposeCapture does.
     *
     * @param OrderModel|\Magento\Sales\Model\Order\Invoice $entity
     * @throws ShopMatchRefusedException
     */
    private function assertShippingTaxReconciles($entity, float $rate): void
    {
        $order = $this->shippingTaxRateOrder($entity);
        $tax = round($this->getTaxAmountShipping($entity), 2);
        $gross = $this->getUnitPriceShipping($entity);
        $net = $gross - $this->getDiscountAmountShipping($order);
        $discrepancy = min(abs($tax - $net * $rate), abs($tax - $gross * $rate));
        if ($discrepancy <= self::TAX_FORMULA_TOLERANCE) {
            return;
        }

        $this->logRepository->addErrorLog(
            'ShippingTaxFallbackMismatch',
            sprintf(
                'Shipping tax %.2F on entity %s does not reconcile with the %.6F%% shipping tax fallback rate '
                . 'on base %.2F (off by %.2F, tolerance %.2F). Magento recorded no rate for the shipping line.',
                $tax,
                $entity->getIncrementId(),
                $rate * 100,
                $net,
                $discrepancy,
                self::TAX_FORMULA_TOLERANCE
            )
        );
        throw new ShopMatchRefusedException($this->shippingTaxRefusal());
    }

    /**
     * Refusal for a shipping line whose tax does not reconcile with the
     * fallback rate. Capture runs after placement, so this default speaks to
     * the merchant; ComposeOrder overrides it with the buyer's wording.
     */
    protected function shippingTaxRefusal(): Phrase
    {
        return __(
            'Shipping tax on this order does not match the rate of the Tax Class for Shipping (Stores > Configuration > Sales > Tax > Tax Classes), which applies because Magento recorded no shipping tax rate.'
        );
    }

    /**
     * Resolves a shipping tax rate through Magento's tax rules engine for
     * core's shipping tax class, with the getRateRequest() arguments core's
     * quote-time calculator uses (Tax\Model\Calculation\AbstractCalculator::
     * getAddressRateRequest()): shipping and billing separately so
     * tax/calculation/based_on picks, the store, the current tax class of
     * the order-time customer group, and the customer id, which core reads
     * for its default-address fallback.
     *
     * Once that group is deleted the class is null, as in core's
     * Quote::getCustomerTaxClassId(): core then uses the customer's current
     * group, or NOT LOGGED IN for a guest or a customer who is also deleted.
     *
     * sales_order stores no customer tax class, so a class edit on that
     * group since placement still changes the rate.
     *
     * A destination with no matching Tax Rule resolves to 0.0, same as an
     * ordinary product line in an untaxed region.
     *
     * @param OrderModel|CreditmemoModel $entity
     */
    public function resolveShippingTaxRateForClass(int $taxClassId, $entity, int $storeId): float
    {
        $order = method_exists($entity, 'getOrder') && $entity->getOrder() ? $entity->getOrder() : $entity;
        $customerTaxClassId = $this->resolveCustomerTaxClassId($order);
        $customerId = method_exists($order, 'getCustomerId') ? $order->getCustomerId() : null;
        // A null class with a customer id makes core load that customer, which throws once they are deleted.
        if ($customerTaxClassId === null && $customerId && !$this->customerExists((int)$customerId)) {
            $customerId = null;
        }
        $request = $this->taxCalculation->getRateRequest(
            $this->resolveShippingAddressForTax($order),
            method_exists($order, 'getBillingAddress') ? $order->getBillingAddress() : null,
            $customerTaxClassId,
            $storeId,
            $customerId
        );
        $request->setProductClassId($taxClassId);
        return (float)$this->taxCalculation->getRate($request) / 100;
    }

    /**
     * Current tax class of the customer group the order was placed under,
     * resolved as core's Quote::getCustomerTaxClassId() does. NULL when the
     * order has no group or it no longer exists, leaving getRateRequest() to
     * resolve the class from the customer id.
     *
     * @param OrderModel $order
     */
    private function resolveCustomerTaxClassId($order): ?int
    {
        $groupId = method_exists($order, 'getCustomerGroupId') ? $order->getCustomerGroupId() : null;
        if ($groupId === null) {
            return null;
        }
        try {
            return (int)$this->groupRepository->getById((int)$groupId)->getTaxClassId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function customerExists(int $customerId): bool
    {
        try {
            $this->customerRepository->getById($customerId);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * The address a shipping tax rate is resolved against: the order's
     * shipping address, falling back to billing for a virtual order (no
     * shipping address exists) — same fallback getAddress() applies.
     *
     * @param OrderModel $order
     * @return \Magento\Sales\Model\Order\Address|null
     */
    private function resolveShippingAddressForTax($order)
    {
        if (!method_exists($order, 'getShippingAddress')) {
            return null;
        }

        $address = $order->getShippingAddress();
        if (!$address && method_exists($order, 'getIsVirtual') && $order->getIsVirtual()
            && method_exists($order, 'getBillingAddress')
        ) {
            $address = $order->getBillingAddress();
        }

        return $address ?: null;
    }

    /**
     * The shipping tax percentage Magento's own tax engine recorded for the
     * order, or NULL when it recorded none. Summed across applied taxes so a
     * combined rate (e.g. state + city) reports as the single rate the buyer
     * was charged, matching how getTaxRateItem() reads tax_percent.
     *
     * Two sources, in this order:
     *
     * 1. The order's own `item_applied_taxes` extension attribute. At
     *    PLACEMENT time (ComposeOrder, reached from Two::authorize() inside
     *    Order::place(), before the order is ever saved) this is the only
     *    source that exists — the sales_order_tax_item rows are written by
     *    Magento\Tax\Model\Plugin\OrderSave on save, and the order has no
     *    entity id yet to read them by.
     * 2. sales_order_tax_item via OrderTaxManagementInterface, for the
     *    post-save consumers (ComposeCapture, ComposeRefund).
     *
     * @param OrderModel|CreditmemoModel $entity
     * @return float|null
     */
    private function getDeclaredShippingTaxPercent($entity): ?float
    {
        // A creditmemo/invoice relays its parent order's declared rate —
        // a refund does not re-derive tax.
        $order = method_exists($entity, 'getOrder') && $entity->getOrder()
            ? $entity->getOrder()
            : $entity;

        $percent = $this->getAppliedShippingTaxPercent($order);
        if ($percent !== null) {
            return $percent;
        }

        $orderId = (int)$order->getId();
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
     * Shipping tax percentage off the order's own `item_applied_taxes`
     * extension attribute, or NULL when it carries no shipping entry.
     *
     * Two element shapes, both handled: nested arrays before the order is
     * saved (Magento\Tax\Model\Quote\ToOrderConverter builds them during
     * quote->order conversion) and OrderTaxDetailsItemInterface objects
     * after it (Magento\Sales\Model\OrderRepository repopulates the same
     * attribute on load).
     *
     * @param OrderModel|CreditmemoModel $order
     * @return float|null
     */
    private function getAppliedShippingTaxPercent($order): ?float
    {
        if (!method_exists($order, 'getExtensionAttributes')) {
            return null;
        }
        $extensionAttributes = $order->getExtensionAttributes();
        if ($extensionAttributes === null
            || !method_exists($extensionAttributes, 'getItemAppliedTaxes')) {
            return null;
        }

        $percent = null;
        foreach ($extensionAttributes->getItemAppliedTaxes() ?? [] as $taxItem) {
            $type = is_array($taxItem) ? ($taxItem['type'] ?? null) : $taxItem->getType();
            if ($type !== CommonTaxCollector::ITEM_TYPE_SHIPPING) {
                continue;
            }
            $appliedTaxes = is_array($taxItem)
                ? ($taxItem['applied_taxes'] ?? [])
                : ($taxItem->getAppliedTaxes() ?? []);
            foreach ($appliedTaxes as $appliedTax) {
                $percent = ($percent ?? 0.0) + (float)(
                    is_array($appliedTax) ? ($appliedTax['percent'] ?? 0) : $appliedTax->getPercent()
                );
            }
        }

        return $percent;
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
     * (see that method's docblock). It reconciles the payload only — a fee
     * whose extension runs its own credit-memo collector still needs a
     * FeeLineProviderInterface, because Model\Total\Creditmemo\OtherCharges
     * offers whatever reaches this residual to the merchant to refund.
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
     * quote it was converted from). An Invoice or Creditmemo residual is a
     * share of the same order-level fee, taxed at the same order-level
     * rate, so those entities are resolved to their own order and read the
     * rate from there.
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
        $order = $this->resolveOrder($entity);
        if (!$order) {
            return null;
        }

        $appliedTaxes = $this->getOrderAppliedTaxes($order);
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
     * Every rate Magento's own tax engine applied to this order.
     *
     * The extension attribute is the only source at placement, before the
     * order has an id; the admin invoice and credit-memo controllers load
     * through OrderFactory, which never populates it. A fee contributed by a
     * total collector has no taxable item row, so its rate lands only in the
     * order-level rows.
     *
     * All sources are read, not the first populated one: an order carrying
     * its products' rate in the item rows must not shadow a differently-taxed
     * fee's rate in the order-level rows.
     *
     * @param OrderModel $order
     * @return iterable
     */
    private function getOrderAppliedTaxes(OrderModel $order): iterable
    {
        $extensionAttributes = $order->getExtensionAttributes();
        $appliedTaxes = [];
        foreach (($extensionAttributes ? $extensionAttributes->getAppliedTaxes() : null) ?: [] as $appliedTax) {
            $appliedTaxes[] = $appliedTax;
        }

        $orderId = (int)$order->getId();
        if ($orderId <= 0) {
            return $appliedTaxes;
        }

        try {
            foreach ($this->orderTaxManagement->getOrderTaxDetails($orderId)->getAppliedTaxes() ?? [] as $itemTax) {
                $appliedTaxes[] = $itemTax;
            }
        } catch (Exception $exception) {
            // An unreadable source is not a refusal; the others still count.
        }

        try {
            foreach ($this->orderTaxCollectionFactory->create()->loadByOrder($order) as $orderTax) {
                $appliedTaxes[] = $orderTax;
            }
        } catch (Exception $exception) {
            // An unreadable source is not a refusal; the others still count.
        }

        return $appliedTaxes;
    }

    /**
     * The gross and tax of every line composition itemizes, for callers that
     * need the order's known amounts rather than its payload.
     *
     * One entry per line so getOtherChargesLineItem()'s count-scaled epsilon
     * matches what composition sees. Amounts come from the same accessors, so
     * the two cannot disagree; unlike getLineItemsOrder() this loads no
     * products, and so cannot drop an item whose product has been deleted —
     * which would turn that item's own value into a phantom residual.
     *
     * @param OrderModel $order
     * @return array
     * @throws LocalizedException
     */
    public function getKnownLineAmountsOrder(OrderModel $order): array
    {
        $amounts = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $amounts[] = [
                'gross_amount' => $this->roundAmt($this->getGrossAmountItem($item)),
                'tax_amount' => $this->roundAmt($this->getTaxAmountItem($item)),
            ];
        }

        if (!$order->getIsVirtual() && $order->getShippingAmount() > 0) {
            // Not getShippingLineOrder(): resolving the RATE can throw.
            $amounts[] = [
                'gross_amount' => $this->roundAmt($this->getGrossAmountShipping($order)),
                'tax_amount' => $this->roundAmt($this->getTaxAmountShipping($order)),
            ];
        }

        $surchargeNet = (float)$order->getTwoSurchargeAmount();
        if ($surchargeNet > 0) {
            $surchargeTax = (float)$order->getTwoSurchargeTaxAmount();
            $amounts[] = [
                'gross_amount' => $this->roundAmt($surchargeNet + $surchargeTax),
                'tax_amount' => $this->roundAmt($surchargeTax),
            ];
        }

        return $amounts;
    }

    /**
     * The order carrying the order-level facts for any of the three
     * entities the compose services reconcile.
     *
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @return OrderModel|null
     */
    private function resolveOrder($entity): ?OrderModel
    {
        if ($entity instanceof OrderModel) {
            return $entity;
        }

        if ($entity instanceof OrderModel\Invoice || $entity instanceof OrderModel\Creditmemo) {
            $order = $entity->getOrder();

            return $order instanceof OrderModel ? $order : null;
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
