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
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Abstract order class
 */
abstract class Order
{
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
     * Order constructor.
     *
     * @param Image $imageHelper
     * @param ConfigRepository $configRepository
     * @param CategoryCollection $categoryCollectionFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param Emulation $appEmulation
     * @param Url $url
     */
    public function __construct(
        Image $imageHelper,
        ConfigRepository $configRepository,
        CategoryCollection $categoryCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        Emulation $appEmulation,
        Url $url
    ) {
        $this->imageHelper = $imageHelper;
        $this->configRepository = $configRepository;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->orderItemRepository = $orderItemRepository;
        $this->appEmulation = $appEmulation;
        $this->url = $url;
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
                'tax_rate' => $this->roundAmt($this->getTaxRateItem($item)),
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
     * @param OrderItem|InvoiceItem|CreditmemoItem $item
     *
     * @return float
     */
    public function getDiscountAmountItem($item): float
    {
        return (float)$item->getDiscountAmount() - (float)$item->getDiscountTaxCompensationAmount();
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
            'tax_rate' => $this->roundAmt($this->getTaxRateShipping($order)),
            'unit_price' => $this->roundAmt($this->getUnitPriceShipping($order), 6),
            'tax_class_name' => 'VAT ' . $this->roundAmt($this->getTaxRateShipping($order) * 100) . '%',
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
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getDiscountAmountShipping($entity): float
    {
        return (float)$entity->getShippingDiscountAmount() - (float)$entity->getShippingDiscountTaxCompensationAmount();
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
     * @param OrderModel|CreditmemoModel $entity
     * @return float
     */
    public function getTaxRateShipping($entity): float
    {
        return ($entity->getShippingInclTax() / $entity->getShippingAmount()) - 1;
    }

    /**
     * @param OrderModel $order
     * @param array|null $additionalData
     * @param string $type
     * @return array
     */
    public function getAddress(OrderModel $order, ?array $additionalData, string $type): array
    {
        $address = !$order->getIsVirtual() || $type != 'billing'
            ? $order->getShippingAddress()
            : $order->getBillingAddress();

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
                'tax_rate' => $this->roundAmt($taxRate)
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
