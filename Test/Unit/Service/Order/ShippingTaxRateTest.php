<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsItemInterface;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;
use Two\Gateway\Service\Order\ComposeOrder;

/**
 * TWO-25503: the shipping line's tax_rate is whatever Magento's tax engine
 * declared for that line — never tax/net. A derived quotient lands on rates
 * no tax rule declares as soon as rounding, mixed rates or a shipping
 * discount are involved, and Two validates the declared rate against the
 * line's own amounts.
 *
 * Where nothing is declared: an untaxed line is 0% (a statement, not a
 * guess); a taxed line falls back to Magento's own shipping tax class
 * (tax/classes/shipping_tax_class), and refuses the order when that is unset.
 */
class ShippingTaxRateTest extends TestCase
{
    /** Tax class of each customer group; group 0 is NOT LOGGED IN. */
    private const CLASS_BY_GROUP = [0 => 3, 1 => 3, 2 => 10];

    /** Customer id => tax class of their CURRENT group; customer 43 was deleted. */
    private const CURRENT_CLASS_BY_CUSTOMER = [42 => 3];

    /** Percent keyed "<store>/<product class>/<customer class>". */
    private const RATES = ['1/5/3' => 25.0, '1/5/10' => 12.0, '2/7/3' => 15.0, '1/6/3' => 0.0];

    /** @var array<int, array> getRateRequest() arguments, one entry per call */
    private $rateRequests = [];

    /**
     * @param float|null $declaredPercent percent Magento declares for shipping, null for none
     * @param array<int, int> $classByStore core tax/classes/shipping_tax_class per store id, absent for unset
     * @param array<int, bool> $fallbackByStore enable_shipping_tax_fallback per store id, absent for off
     * @param string $service the Order subclass under test, which decides the refusal wording
     * @return Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private function orderService(
        ?float $declaredPercent,
        array $classByStore = [],
        array $fallbackByStore = [1 => true, 2 => true],
        string $service = Order::class
    ) {
        $orderService = $this->getMockForAbstractClass($service, [], '', false);

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isShippingTaxFallbackEnabled')->willReturnCallback(
            static fn (?int $storeId) => $fallbackByStore[$storeId] ?? false
        );
        $configRepository->method('getShippingTaxClassId')->willReturnCallback(
            static fn (?int $storeId) => $classByStore[$storeId] ?? null
        );
        $orderService->configRepository = $configRepository;

        $groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $groupRepository->method('getById')->willReturnCallback(
            static fn ($id) => new DataObject([
                'taxClassId' => self::CLASS_BY_GROUP[$id] ?? throw NoSuchEntityException::singleField('id', $id),
            ])
        );

        $this->setProperty($orderService, 'logRepository', $this->createMock(LogRepository::class));
        $this->setProperty($orderService, 'orderTaxManagement', $this->taxManagement($declaredPercent));
        $this->setProperty($orderService, 'taxCalculation', $this->taxCalculation());
        $this->setProperty($orderService, 'groupRepository', $groupRepository);

        return $orderService;
    }

    private function setProperty(object $target, string $name, $value): void
    {
        $property = new \ReflectionProperty(Order::class, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    /**
     * Core's getRateRequest()/getRate(): a null customer tax class makes core
     * read the customer's CURRENT group, and a deleted customer throws there
     * (Tax\Model\Calculation::getRateRequest() @2.4.7).
     */
    private function taxCalculation(): TaxCalculation
    {
        $taxCalculation = $this->createMock(TaxCalculation::class);
        $taxCalculation->method('getRateRequest')->willReturnCallback(
            function ($shipping, $billing, $customerClass, $store, $customerId = null) {
                $this->rateRequests[] = [$shipping, $billing, $customerClass, $store, $customerId];
                $customerClass ??= $customerId
                    ? (self::CURRENT_CLASS_BY_CUSTOMER[$customerId]
                        ?? throw NoSuchEntityException::singleField('customerId', $customerId))
                    : self::CLASS_BY_GROUP[0];
                return new DataObject(['store' => $store, 'customerClassId' => $customerClass]);
            }
        );
        $taxCalculation->method('getRate')->willReturnCallback(
            static fn ($request) => self::RATES[
                $request->getStore() . '/' . $request->getProductClassId() . '/' . $request->getCustomerClassId()
            ] ?? 0.0
        );

        return $taxCalculation;
    }

    /**
     * @param float|null $declaredPercent one applied tax at this percent, or no shipping item at all
     */
    private function taxManagement(?float $declaredPercent): OrderTaxManagementInterface
    {
        $items = [];
        if ($declaredPercent !== null) {
            $items[] = $this->taxItem('shipping', [$declaredPercent]);
        }
        // A product tax item is always present and must be ignored — it is
        // the shipping-typed item alone that declares the shipping rate.
        $items[] = $this->taxItem('product', [12.0]);

        return $this->taxManagementFor($items);
    }

    private function taxManagementFor(array $items): OrderTaxManagementInterface
    {
        $details = $this->createMock(OrderTaxDetailsInterface::class);
        $details->method('getItems')->willReturn($items);

        $management = $this->createMock(OrderTaxManagementInterface::class);
        $management->method('getOrderTaxDetails')->willReturn($details);

        return $management;
    }

    private function taxItem(string $type, array $percents): OrderTaxDetailsItemInterface
    {
        $appliedTaxes = [];
        foreach ($percents as $percent) {
            $appliedTax = $this->createMock(OrderTaxDetailsAppliedTaxInterface::class);
            $appliedTax->method('getPercent')->willReturn($percent);
            $appliedTaxes[] = $appliedTax;
        }

        $item = $this->createMock(OrderTaxDetailsItemInterface::class);
        $item->method('getType')->willReturn($type);
        $item->method('getAppliedTaxes')->willReturn($appliedTaxes);

        return $item;
    }

    /**
     * An order, or with 'order' set a credit memo of that order, answering
     * the getters the shipping tax path reads. Methods are declared rather
     * than magic because the service probes them with method_exists().
     *
     * @param array<string, mixed> $data
     */
    private function entity(array $data): object
    {
        return new class ($data + [
            'id' => 7,
            'order' => null,
            'item_applied_taxes' => null,
            'shipping_tax_amount' => 0.0,
            'shipping_address' => null,
            'billing_address' => null,
            'is_virtual' => false,
            'customer_id' => null,
            'customer_group_id' => 0,
            'store_id' => 1,
        ]) {
            /** @var array<string, mixed> */
            private $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function getId()
            {
                return $this->data['id'];
            }

            public function getOrder()
            {
                return $this->data['order'];
            }

            public function getExtensionAttributes()
            {
                return $this->data['item_applied_taxes'] === null ? null
                    : new class ($this->data['item_applied_taxes']) {
                        /** @var array */
                        private $taxes;

                        public function __construct(array $taxes)
                        {
                            $this->taxes = $taxes;
                        }

                        public function getItemAppliedTaxes(): array
                        {
                            return $this->taxes;
                        }
                    };
            }

            public function getShippingTaxAmount(): float
            {
                return $this->data['shipping_tax_amount'];
            }

            public function getShippingAddress()
            {
                return $this->data['shipping_address'];
            }

            public function getBillingAddress()
            {
                return $this->data['billing_address'];
            }

            public function getIsVirtual(): bool
            {
                return $this->data['is_virtual'];
            }

            public function getCustomerId()
            {
                return $this->data['customer_id'];
            }

            public function getCustomerGroupId()
            {
                return $this->data['customer_group_id'];
            }

            public function getStoreId(): int
            {
                return $this->data['store_id'];
            }

            public function getIncrementId(): string
            {
                return '100000007';
            }
        };
    }

    public function testDeclaredRateIsRelayedRatherThanDerived(): void
    {
        // Given Magento declares 25% on a shipping line whose amounts divide
        // to 24%; When composing; Then the declared rate wins.
        $rate = $this->orderService(25.0)->getTaxRateShipping($this->entity(['shipping_tax_amount' => 24.00]));

        $this->assertSame(0.25, $rate);
    }

    public function testCombinedDeclaredRatesSumToTheRateTheBuyerPaid(): void
    {
        // Given state + city tax on shipping; When composing; Then one rate.
        $orderService = $this->orderService(null);
        $this->setProperty(
            $orderService,
            'orderTaxManagement',
            $this->taxManagementFor([$this->taxItem('shipping', [6.0, 2.5])])
        );

        $this->assertSame(0.085, $orderService->getTaxRateShipping($this->entity(['shipping_tax_amount' => 8.50])));
    }

    public function testUntaxedShippingNeedsNoDeclarationAndNoFallback(): void
    {
        // Given no declared rate and no shipping tax; When composing; Then
        // 0% — no fallback consulted, no refusal.
        $this->assertSame(0.0, $this->orderService(null)->getTaxRateShipping($this->entity([])));
    }

    /**
     * An order composed at PLACEMENT time has no id and no tax rows: it is
     * still inside Two::authorize(), called from Order::place() before
     * orderRepository->save(). The rate has to come off the order's own
     * item_applied_taxes extension attribute, which quote->order conversion
     * populated, and the persisted read must not even be attempted.
     *
     * @dataProvider placementAppliedTaxes
     */
    public function testThePlacementPathReadsTheUnsavedOrdersOwnAppliedTaxes(
        array $itemAppliedTaxes,
        ?float $expected,
        string $case
    ): void {
        // No core shipping tax class, so a rate that fails to come off the
        // extension attribute is refused rather than masked by a fallback.
        $orderService = $this->orderService(null);
        $taxManagement = $this->createMock(OrderTaxManagementInterface::class);
        $taxManagement->expects($this->never())->method('getOrderTaxDetails');
        $this->setProperty($orderService, 'orderTaxManagement', $taxManagement);

        $entity = $this->entity(['id' => null, 'shipping_tax_amount' => 25.00, 'item_applied_taxes' => $itemAppliedTaxes]);

        if ($expected === null) {
            $this->expectException(LocalizedException::class);
            $orderService->getTaxRateShipping($entity);
            return;
        }

        $this->assertSame($expected, $orderService->getTaxRateShipping($entity), $case);
    }

    public function placementAppliedTaxes(): array
    {
        return [
            [
                [['type' => 'shipping', 'applied_taxes' => [['percent' => 25.0]]]],
                0.25,
                'single declared shipping rate',
            ],
            [
                [['type' => 'shipping', 'applied_taxes' => [['percent' => 6.0], ['percent' => 2.5]]]],
                0.085,
                'combined rates sum to what the buyer paid',
            ],
            [
                [
                    ['type' => 'product', 'applied_taxes' => [['percent' => 12.0]]],
                    ['type' => 'shipping', 'applied_taxes' => [['percent' => 25.0]]],
                ],
                0.25,
                'the product-typed entry is not the shipping rate',
            ],
            [
                [['type' => 'product', 'applied_taxes' => [['percent' => 12.0]]]],
                null,
                'no shipping entry at all: refused, never the product rate',
            ],
        ];
    }

    // ── TWO-26073: no declared rate falls back to core's
    // tax/classes/shipping_tax_class, resolved per store ────────────────

    /**
     * Resolves the rate, then runs the shipping line through the same
     * reconciliation gate ComposeOrder applies; null means refused.
     *
     * @param array<int, int> $classByStore
     * @dataProvider coreShippingTaxClassCases
     */
    public function testShippingTaxRateFallsBackToTheCoreShippingTaxClass(
        ?float $declaredPercent,
        int $storeId,
        array $classByStore,
        float $net,
        float $shippingTax,
        float $discount,
        ?float $expected,
        string $case
    ): void {
        $orderService = $this->orderService($declaredPercent, $classByStore);
        $entity = $this->entity([
            'shipping_tax_amount' => $shippingTax,
            'shipping_address' => new DataObject(['country_id' => 'NO']),
            'store_id' => $storeId,
        ]);

        try {
            $rate = $orderService->getTaxRateShipping($entity);
            $orderService->validateTaxReconciliation([[
                'order_item_id' => 'shipping',
                'net_amount' => $net,
                'tax_amount' => $shippingTax,
                'discount_amount' => $discount,
                'tax_rate' => $rate,
                'quantity' => 1,
            ]]);
        } catch (LocalizedException $e) {
            $rate = null;
        }

        $this->assertSame($expected, $rate, $case);
    }

    public static function coreShippingTaxClassCases(): array
    {
        return [
            [19.0, 1, [1 => 5], 100.00, 19.00, 0.00, 0.19, 'declared rate present: relayed, core class not consulted'],
            [null, 1, [1 => 5], 100.00, 25.00, 0.00, 0.25, 'no declared rate: resolved via the core shipping tax class'],
            [null, 1, [], 100.00, 25.00, 0.00, null, 'core shipping tax class None (0) or unset, shipping taxed: refused'],
            [null, 1, [], 100.00, 0.00, 0.00, 0.0, 'core shipping tax class None (0) or unset, shipping untaxed: 0%'],
            [0.0, 1, [], 100.00, 0.00, 0.00, 0.0, 'core shipping tax class None (0) or unset, declared 0%: accepted'],
            [null, 1, [1 => 5], 100.00, 19.00, 0.00, null, 'core class rate does not reconcile with the shipping tax: refused'],
            [null, 2, [1 => 5, 2 => 7], 100.00, 15.00, 0.00, 0.15, 'multi-store: store 2 reads its own class and rate'],
            [null, 1, [1 => 6], 100.00, 0.01, 0.00, 0.0, 'zero-rate class within tolerance: a real resolution, not unset'],
            [null, 1, [1 => 6], 100.00, 5.00, 0.00, null, 'zero-rate class with tax above tolerance: refused'],
            [null, 1, [1 => 5], 80.00, 20.00, 20.00, 0.25, 'shipping discount: class rate on the discounted base'],
        ];
    }

    /**
     * getRateRequest() gets core's quote-time arguments: shipping and
     * billing separately, the store, the tax class of the group the order
     * was placed under, and the customer id.
     *
     * @dataProvider customerTaxClassCases
     */
    public function testCoreClassResolutionUsesTheOrderTimeCustomerTaxClass(
        bool $isCreditmemo,
        bool $isVirtual,
        int $groupId,
        ?int $customerId,
        float $shippingTax,
        ?int $expectedCustomerClass,
        float $expected,
        string $case
    ): void {
        $shippingAddress = $isVirtual ? null : new DataObject(['country_id' => 'NO']);
        $billingAddress = new DataObject(['country_id' => 'SE']);
        $order = $this->entity([
            'shipping_tax_amount' => 12.00,
            'shipping_address' => $shippingAddress,
            'billing_address' => $billingAddress,
            'is_virtual' => $isVirtual,
            'customer_id' => $customerId,
            'customer_group_id' => $groupId,
        ]);
        $entity = $isCreditmemo ? $this->entity(['order' => $order, 'shipping_tax_amount' => $shippingTax]) : $order;

        $rate = $this->orderService(null, [1 => 5])->getTaxRateShipping($entity);

        $this->assertSame($expected, $rate, $case);
        $this->assertSame(
            [[$shippingAddress ?? $billingAddress, $billingAddress, $expectedCustomerClass, 1, $customerId]],
            $this->rateRequests,
            $case
        );
    }

    public static function customerTaxClassCases(): array
    {
        return [
            [false, false, 0, null, 25.00, 3, 0.25, 'guest: NOT LOGGED IN class'],
            [false, false, 2, 42, 12.00, 10, 0.12, 'capture after a customer group change: order-time class'],
            [false, false, 2, 43, 12.00, 10, 0.12, 'deleted customer: order-time class, no customer lookup'],
            [false, false, 99, 42, 25.00, null, 0.25, 'order group deleted: core falls back to the current group'],
            [true, false, 2, 42, 12.00, 10, 0.12, 'refund after a customer group change: order-time class'],
            [true, false, 2, 42, 6.00, 10, 0.12, 'partial credit memo: order-time rate on the refunded shipping'],
            [false, true, 2, 42, 12.00, 10, 0.12, 'virtual order: billing stands in for shipping'],
        ];
    }

    // ── TWO-26082: the fallback is off unless enabled per store by CLI ──

    private const MERCHANT_DISABLED = 'Shipping tax could not be determined for this order: Magento recorded no'
        . ' shipping tax rate and the shipping tax fallback is not enabled for this store.';

    private const BUYER_REFUSAL = 'This order could not be placed. Please contact the merchant.';

    /**
     * @param array<int, bool> $fallbackByStore
     * @dataProvider fallbackFlagCases
     */
    public function testShippingTaxFallbackIsOffUnlessEnabledForTheStore(
        string $service,
        int $storeId,
        array $fallbackByStore,
        float $shippingTax,
        $expected,
        string $case
    ): void {
        $orderService = $this->orderService(null, [1 => 5, 2 => 7], $fallbackByStore, $service);
        $entity = $this->entity([
            'shipping_tax_amount' => $shippingTax,
            'shipping_address' => new DataObject(['country_id' => 'NO']),
            'store_id' => $storeId,
        ]);

        try {
            $actual = $orderService->getTaxRateShipping($entity);
        } catch (LocalizedException $e) {
            $actual = $e->getMessage();
        }

        $this->assertSame($expected, $actual, $case);
    }

    public static function fallbackFlagCases(): array
    {
        return [
            [Order::class, 1, [], 25.00, self::MERCHANT_DISABLED, 'disabled, needs fallback: refused at capture/refund'],
            [ComposeOrder::class, 1, [], 25.00, self::BUYER_REFUSAL, 'disabled, needs fallback: refused at placement'],
            [Order::class, 1, [1 => true], 25.00, 0.25, 'enabled, needs fallback: resolved via core class'],
            [Order::class, 1, [], 0.00, 0.0, 'disabled, untaxed shipping: 0% accepted'],
            [Order::class, 2, [2 => true], 15.00, 0.15, 'scope: enabled on store 2 only, store 2 resolves'],
            [Order::class, 1, [2 => true], 25.00, self::MERCHANT_DISABLED, 'scope: enabled on store 2 only, store 1 refused'],
        ];
    }
}
