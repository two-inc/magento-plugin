<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Shared fixtures for the CI probes (TWO-26092): a Dutch tax setup (21% and
 * 9%, shipping taxed through core's shipping tax class), simple, configurable
 * and bundle products, a coupon, and real quotes built the way checkout
 * builds them. Idempotent, so the probes can share one Magento.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Integration;

use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\RuleFactory as SalesRuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\ClassModelFactory;

class ProbeFixtures
{
    public const COUPON = 'PROBE10';
    public const STANDARD_CLASS = 2;
    public const ADDRESS = [
        'firstname' => 'Probe',
        'lastname' => 'Buyer',
        'company' => 'Probe BV',
        'street' => ['Damrak 1'],
        'city' => 'Amsterdam',
        'postcode' => '1012 AB',
        'country_id' => 'NL',
        'telephone' => '+31201234567',
        'email' => 'probe@example.com',
    ];

    /** Tax-exclusive prices, the Magento default. */
    public const EXCLUSIVE = [
        'tax/calculation/price_includes_tax' => 0,
        'tax/calculation/shipping_includes_tax' => 0,
        'tax/display/type' => 1,
        'tax/display/shipping' => 1,
        'tax/cart_display/price' => 1,
        'tax/cart_display/subtotal' => 1,
        'tax/cart_display/shipping' => 1,
    ];

    /** Catalog and shipping prices entered and displayed including tax. */
    public const INCLUSIVE = [
        'tax/calculation/price_includes_tax' => 1,
        'tax/calculation/shipping_includes_tax' => 1,
        'tax/display/type' => 2,
        'tax/display/shipping' => 2,
        'tax/cart_display/price' => 2,
        'tax/cart_display/subtotal' => 2,
        'tax/cart_display/shipping' => 2,
    ];

    /** @var ObjectManagerInterface */
    private $om;

    /** @var int */
    private $reducedClass;

    /** @var array{attribute_id: int, option_id: int} */
    private $colour;

    public function __construct(ObjectManagerInterface $om)
    {
        $this->om = $om;
        $om->get(State::class)->emulateAreaCode('adminhtml', function (): void {
            $this->reducedClass = $this->productTaxClass('Probe Reduced');
            $this->taxRule('PROBE-NL-21', 21.0, self::STANDARD_CLASS);
            $this->taxRule('PROBE-NL-9', 9.0, $this->reducedClass);
            $this->configure([
                'tax/classes/shipping_tax_class' => self::STANDARD_CLASS,
                'tax/defaults/country' => 'NL',
                'carriers/flatrate/active' => 1,
                'carriers/flatrate/type' => 'O',
                'carriers/flatrate/price' => 29,
            ] + self::EXCLUSIVE);
            $this->simple('probe-standard', 100.00, self::STANDARD_CLASS);
            $this->simple('probe-reduced', 50.00, $this->reducedClass);
            $this->configurable();
            $this->bundle();
            $this->coupon();
        });
        // CI's indexers run on schedule, and an unindexed product is not salable.
        foreach ($om->create(\Magento\Indexer\Model\Indexer\Collection::class)->getItems() as $indexer) {
            $indexer->reindexAll();
        }
    }

    /**
     * Write config at default scope and drop every cached read of it.
     *
     * @param array<string, mixed> $values
     */
    public function configure(array $values): void
    {
        $writer = $this->om->get(WriterInterface::class);
        foreach ($values as $path => $value) {
            $writer->save($path, (string)$value);
        }
        $this->om->get(ScopeConfigInterface::class)->clean();
    }

    /**
     * A quote as checkout leaves it before placement: items, NL addresses,
     * flat-rate shipping, totals collected, saved.
     *
     * @param array<int, array{0: string, 1: float|int}> $items [sku, qty]
     */
    public function quote(array $items, bool $guest, ?string $coupon = null): Quote
    {
        $store = $this->om->get(StoreManagerInterface::class)->getStore(1);
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($store)->setIsActive(true);
        if ($guest) {
            $quote->setCustomerIsGuest(true)->setCustomerGroupId(0)->setCustomerEmail(self::ADDRESS['email']);
        } else {
            $quote->setCustomerIsGuest(false)->setCustomerGroupId(1)->setCustomerEmail(self::ADDRESS['email']);
        }

        $repository = $this->om->get(ProductRepositoryInterface::class);
        foreach ($items as [$sku, $qty]) {
            $product = $repository->get($sku, false, 1, true);
            $result = $quote->addProduct($product, $this->buyRequest($product, (float)$qty));
            if (is_string($result)) {
                throw new \RuntimeException("$sku not added: $result");
            }
        }

        $quote->getBillingAddress()->addData(self::ADDRESS);
        $shipping = $quote->getShippingAddress()->addData(self::ADDRESS);
        $shipping->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
        if ($coupon !== null) {
            $quote->setCouponCode($coupon);
        }
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->om->get(CartRepositoryInterface::class)->save($quote);

        return $quote;
    }

    private function buyRequest(ProductInterface $product, float $qty): DataObject
    {
        $request = ['qty' => $qty];
        if ($product->getTypeId() === 'configurable') {
            $request['super_attribute'] = [$this->colour['attribute_id'] => $this->colour['option_id']];
        }
        if ($product->getTypeId() === 'bundle') {
            $type = $product->getTypeInstance();
            $selections = $type->getSelectionsCollection($type->getOptionsIds($product), $product);
            foreach ($selections as $selection) {
                $request['bundle_option'][$selection->getOptionId()] = $selection->getSelectionId();
                $request['bundle_option_qty'][$selection->getOptionId()] = 1;
            }
        }

        return new DataObject($request);
    }

    private function productTaxClass(string $name): int
    {
        $class = $this->om->create(\Magento\Tax\Model\ResourceModel\TaxClass\Collection::class)
            ->addFieldToFilter('class_name', $name)->getFirstItem();
        if (!$class->getId()) {
            $class = $this->om->get(ClassModelFactory::class)->create()
                ->setClassName($name)->setClassType('PRODUCT')->save();
        }

        return (int)$class->getId();
    }

    private function taxRule(string $code, float $percent, int $productClass): void
    {
        $existing = $this->om->create(\Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection::class)
            ->addFieldToFilter('code', $code)->getFirstItem();
        if ($existing->getId()) {
            return;
        }
        $rate = $this->om->get(RateFactory::class)->create()->setData([
            'tax_country_id' => 'NL',
            'tax_region_id' => 0,
            'tax_postcode' => '*',
            'code' => $code,
            'rate' => $percent,
        ])->save();
        $rule = $this->om->get(TaxRuleInterfaceFactory::class)->create()
            ->setCode($code)
            ->setCustomerTaxClassIds([3])
            ->setProductTaxClassIds([$productClass])
            ->setTaxRateIds([(int)$rate->getId()])
            ->setPriority(0)
            ->setPosition(0);
        $this->om->get(TaxRuleRepositoryInterface::class)->save($rule);
    }

    private function simple(string $sku, float $price, int $taxClass, int $visibility = Visibility::VISIBILITY_BOTH, array $data = []): ProductInterface
    {
        $repository = $this->om->get(ProductRepositoryInterface::class);
        try {
            return $repository->get($sku, false, null, true);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Created below.
        }
        $product = $this->om->get(ProductFactory::class)->create()->addData($data);
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setSku($sku)
            ->setName($sku)
            ->setPrice($price)
            ->setTaxClassId($taxClass)
            ->setVisibility($visibility)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 1000, 'is_in_stock' => 1]);

        return $repository->save($product);
    }

    private function configurable(): void
    {
        $eav = $this->om->get(EavConfig::class);
        $attribute = $eav->getAttribute('catalog_product', 'probe_colour');
        if (!$attribute || !$attribute->getId()) {
            $this->om->get(EavSetupFactory::class)->create()->addAttribute('catalog_product', 'probe_colour', [
                'type' => 'int',
                'input' => 'select',
                'label' => 'Probe colour',
                'global' => 1,
                'user_defined' => true,
                'required' => false,
                'visible' => true,
                'group' => 'General',
                'option' => ['values' => ['Red']],
            ]);
            $eav->clear();
            $attribute = $eav->getAttribute('catalog_product', 'probe_colour');
        }
        $optionId = (int)$attribute->getSource()->getOptionId('Red');
        $this->colour = ['attribute_id' => (int)$attribute->getId(), 'option_id' => $optionId];

        $child = $this->simple(
            'probe-configurable-red',
            80.00,
            self::STANDARD_CLASS,
            Visibility::VISIBILITY_NOT_VISIBLE,
            ['probe_colour' => $optionId]
        );

        $repository = $this->om->get(ProductRepositoryInterface::class);
        try {
            $repository->get('probe-configurable', false, null, true);
            return;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Created below.
        }
        $options = $this->om->get(ConfigurableOptionsFactory::class)->create([[
            'attribute_id' => $attribute->getId(),
            'code' => 'probe_colour',
            'label' => 'Probe colour',
            'position' => 0,
            'values' => [['label' => 'Red', 'attribute_id' => $attribute->getId(), 'value_index' => $optionId]],
        ]]);
        $parent = $this->om->get(ProductFactory::class)->create();
        $extension = $parent->getExtensionAttributes();
        $extension->setConfigurableProductOptions($options);
        $extension->setConfigurableProductLinks([(int)$child->getId()]);
        $parent->setExtensionAttributes($extension);
        $parent->setTypeId('configurable')
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setSku('probe-configurable')
            ->setName('probe-configurable')
            ->setTaxClassId(self::STANDARD_CLASS)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
        $repository->save($parent);
        // 2.4.7 saves the parent out of stock, judging before its child links exist.
        $stockRegistry = $this->om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
        $stockItem = $stockRegistry->getStockItemBySku('probe-configurable');
        $stockRegistry->updateStockItemBySku('probe-configurable', $stockItem->setIsInStock(true));
    }

    /** Dynamic-price bundle of one standard-rate and one reduced-rate product. */
    private function bundle(): void
    {
        $repository = $this->om->get(ProductRepositoryInterface::class);
        try {
            $repository->get('probe-bundle', false, null, true);
            return;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Created below.
        }
        $options = [];
        foreach (['probe-standard', 'probe-reduced'] as $position => $sku) {
            $link = $this->om->get(LinkInterfaceFactory::class)->create()
                ->setSku($sku)->setQty(1)->setCanChangeQuantity(0)->setIsDefault(true)->setPosition($position);
            $options[] = $this->om->get(OptionInterfaceFactory::class)->create()
                ->setTitle($sku)->setType('select')->setRequired(true)->setPosition($position)
                ->setSku('probe-bundle')->setProductLinks([$link]);
        }
        $bundle = $this->om->get(ProductFactory::class)->create();
        $bundle->setTypeId('bundle')
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setSku('probe-bundle')
            ->setName('probe-bundle')
            ->setPriceType(0)
            ->setPriceView(0)
            ->setSkuType(0)
            ->setWeightType(0)
            ->setShipmentType(0)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
        $extension = $bundle->getExtensionAttributes();
        $extension->setBundleProductOptions($options);
        $bundle->setExtensionAttributes($extension);
        $repository->save($bundle);
    }

    private function coupon(): void
    {
        $rule = $this->om->get(SalesRuleFactory::class)->create();
        $existing = $this->om->create(\Magento\SalesRule\Model\ResourceModel\Rule\Collection::class)
            ->addFieldToFilter('name', 'Probe coupon')->getFirstItem();
        if ($existing->getId()) {
            return;
        }
        $rule->setData([
            'name' => 'Probe coupon',
            'is_active' => 1,
            'website_ids' => [1],
            'customer_group_ids' => [0, 1, 2, 3],
            'coupon_type' => 2,
            'coupon_code' => self::COUPON,
            'uses_per_coupon' => 0,
            'uses_per_customer' => 0,
            'simple_action' => 'by_percent',
            'discount_amount' => 10,
            'apply_to_shipping' => 0,
            'stop_rules_processing' => 0,
        ])->save();
    }
}
