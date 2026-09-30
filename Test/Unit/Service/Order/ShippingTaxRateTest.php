<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Customer\Api\CustomerRepositoryInterface;
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
 * Where nothing is declared, the line goes at 0% as charged unless the
 * shipping tax fallback is populated (TWO-26117): see the behaviour table.
 */
class ShippingTaxRateTest extends TestCase
{
    /** Tax class of each customer group; group 0 is NOT LOGGED IN. */
    private const CLASS_BY_GROUP = [0 => 3, 1 => 3, 2 => 10];

    /** Customer id => tax class of their CURRENT group; customer 43 was deleted. */
    private const CURRENT_CLASS_BY_CUSTOMER = [42 => 11];

    /** Percent keyed "<store>/<product class>/<customer class>". */
    private const RATES = ['1/5/3' => 25.0, '1/5/10' => 12.0, '1/5/11' => 20.0, '2/7/3' => 15.0, '1/6/3' => 0.0];

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
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturnCallback(
            static fn ($id) => isset(self::CURRENT_CLASS_BY_CUSTOMER[$id])
                ? new DataObject(['id' => $id])
                : throw NoSuchEntityException::singleField('customerId', $id)
        );
        $this->setProperty($orderService, 'customerRepository', $customerRepository);

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
            'shipping_amount' => 100.0,
            'shipping_discount_amount' => 0.0,
            'shipping_tax_amount' => 0.0,
            'shipping_address' => null,
            'billing_address' => null,
            'is_virtual' => false,
            'customer_id' => null,
            'customer_group_id' => 0,
            'store_id' => 1,
            'two_shipping_tax_rate_source' => null,
            'two_shipping_tax_rate' => null,
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

            public function getData(string $key)
            {
                return $this->data[$key] ?? null;
            }

            public function setData(string $key, $value): void
            {
                $this->data[$key] = $value;
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

            public function getShippingAmount(): float
            {
                return $this->data['shipping_amount'];
            }

            public function getShippingDiscountAmount(): float
            {
                return $this->data['shipping_discount_amount'];
            }

            public function getShippingDiscountTaxCompensationAmount(): float
            {
                return 0.0;
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
        float $expected,
        string $case
    ): void {
        // Control blank, so a rate that fails to come off the extension
        // attribute is sent as 0%, never masked by a fallback.
        $orderService = $this->orderService(null);
        $taxManagement = $this->createMock(OrderTaxManagementInterface::class);
        $taxManagement->expects($this->never())->method('getOrderTaxDetails');
        $this->setProperty($orderService, 'orderTaxManagement', $taxManagement);

        $entity = $this->entity(['id' => null, 'shipping_tax_amount' => 25.00, 'item_applied_taxes' => $itemAppliedTaxes]);

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
                0.0,
                'no shipping entry at all: no rate, never the product rate',
            ],
        ];
    }

    // ── TWO-26117: the behaviour table. A rate Magento recorded, 0% included,
    // is sent as is and never checked by the plugin. With no rate recorded,
    // a blank control sends the line at 0% as charged; a populated one
    // (fallback enabled AND a Tax Class for Shipping set) resolves the rate
    // from that class and refuses a line whose tax does not reconcile. ──

    private const REFUSED = 'Shipping tax on this order does not match the rate of the Tax Class for Shipping'
        . ' (Stores > Configuration > Sales > Tax > Tax Classes), which applies because Magento recorded no'
        . ' shipping tax rate.';

    private const BUYER_REFUSAL = 'This order could not be placed. Please contact the merchant.';

    /** Control name => [tax/classes/shipping_tax_class per store, enable_shipping_tax_fallback per store]. */
    private const CONTROLS = [
        'blank' => [[], []],
        'enabled, no class' => [[], [1 => true]],
        'class, not enabled' => [[1 => 5], []],
        'populated' => [[1 => 5], [1 => true]],
        'populated at 0%' => [[1 => 6], [1 => true]],
        'populated on store 2' => [[1 => 5, 2 => 7], [2 => true]],
    ];

    /**
     * Resolves the rate, then runs the composed shipping line through the
     * builder's line tax reconcile, as ComposeOrder does before the hook.
     * A string expectation is the refusal message.
     *
     * @param float|string $expected
     * @dataProvider behaviourTable
     */
    public function testTheShippingRateFollowsTheBehaviourTable(
        string $control,
        ?float $declaredPercent,
        float $shipping,
        float $discount,
        float $tax,
        $expected,
        string $case,
        int $storeId = 1,
        string $service = Order::class
    ): void {
        [$classByStore, $fallbackByStore] = self::CONTROLS[$control];
        $orderService = $this->orderService($declaredPercent, $classByStore, $fallbackByStore, $service);
        $entity = $this->entity([
            'shipping_amount' => $shipping,
            'shipping_discount_amount' => $discount,
            'shipping_tax_amount' => $tax,
            'shipping_address' => new DataObject(['country_id' => 'NO']),
            'store_id' => $storeId,
        ]);

        try {
            $actual = $orderService->getTaxRateShipping($entity);
            $orderService->validateTaxReconciliation([[
                'order_item_id' => 'shipping',
                'net_amount' => $shipping - $discount,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'tax_rate' => $actual,
                'quantity' => 1,
            ]]);
        } catch (LocalizedException $e) {
            $actual = $e->getMessage();
        }

        $this->assertSame($expected, $actual, $case);
    }

    public static function behaviourTable(): array
    {
        return [
            ['blank', 25.0, 100.00, 0.00, 25.00, 0.25, 'rate provided, control blank: sent as recorded'],
            ['blank', 25.0, 100.00, 0.00, 19.00, 0.25, 'rate provided, control blank, tax off the rate: sent, the API validates'],
            ['blank', 0.0, 100.00, 0.00, 0.00, 0.0, 'explicit 0% provided, control blank: sent as recorded'],
            ['blank', 0.0, 100.00, 0.00, 5.00, 0.0, 'explicit 0% provided with tax, control blank: sent, the API validates'],
            ['populated', 19.0, 100.00, 0.00, 19.00, 0.19, 'rate provided, control populated: the recorded rate, not the control'],
            ['populated', 0.0, 100.00, 0.00, 0.00, 0.0, 'explicit 0% provided, control populated: not resolved from the control'],
            ['populated', 19.0, 100.00, 0.00, 25.00, 0.19, 'rate provided, control populated, tax off the rate: sent, no plugin check'],
            ['blank', null, 100.00, 0.00, 0.00, 0.0, 'no rate, control blank, untaxed: rate 0'],
            ['blank', null, 100.00, 0.00, 25.00, 0.0, 'no rate, control blank, taxed: rate 0 and the tax as charged, never refused'],
            ['enabled, no class', null, 100.00, 0.00, 25.00, 0.0, 'no rate, fallback on but no Tax Class for Shipping: blank'],
            ['class, not enabled', null, 100.00, 0.00, 25.00, 0.0, 'no rate, Tax Class for Shipping set but fallback off: blank'],
            ['populated', null, 100.00, 0.00, 25.00, 0.25, 'no rate, control populated, tax reconciles: the control rate'],
            ['populated', null, 100.00, 0.00, 0.00, self::REFUSED, 'no rate, control populated, untaxed: resolved from the control and refused'],
            ['populated', null, 100.00, 0.00, 19.00, self::REFUSED, 'no rate, control populated, tax off the rate: refused'],
            ['populated', null, 100.00, 0.00, 25.02, 0.25, 'no rate, control populated, at the 0.02 tolerance: sent'],
            ['populated', null, 100.00, 0.00, 25.03, self::REFUSED, 'no rate, control populated, past the 0.02 tolerance: refused'],
            ['populated at 0%', null, 100.00, 0.00, 0.00, 0.0, 'no rate, control resolves 0%, untaxed: 0%'],
            ['populated at 0%', null, 100.00, 0.00, 5.00, self::REFUSED, 'no rate, control resolves 0%, taxed: refused'],
            ['populated', null, 100.00, 20.00, 20.00, 0.25, 'no rate, control populated, shipping discount: the discounted base'],
            ['populated', null, 100.00, 20.00, 25.00, 0.25, 'no rate, control populated, Before Discount: the undiscounted base'],
            ['populated on store 2', null, 100.00, 0.00, 15.00, 0.15, 'scope: store 2 is populated and reads its own class', 2],
            ['populated on store 2', null, 100.00, 0.00, 25.00, 0.0, 'scope: store 1 is blank, sent as is', 1],
            ['populated', null, 100.00, 0.00, 0.00, self::BUYER_REFUSAL, 'refused at placement in the buyer wording', 1, ComposeOrder::class],
        ];
    }

    /**
     * Placement records which case of the table applied, and the fallback's
     * rate, on the order it composes.
     *
     * @dataProvider placementRecords
     */
    public function testPlacementRecordsTheCaseOnTheOrder(
        string $control,
        ?float $declaredPercent,
        float $tax,
        array $expected,
        string $case
    ): void {
        [$classByStore, $fallbackByStore] = self::CONTROLS[$control];
        $taxes = $declaredPercent === null ? [] : [['type' => 'shipping', 'applied_taxes' => [['percent' => $declaredPercent]]]];
        $order = $this->entity([
            'id' => null,
            'item_applied_taxes' => $taxes,
            'shipping_tax_amount' => $tax,
            'shipping_address' => new DataObject(),
        ]);

        $this->orderService(null, $classByStore, $fallbackByStore)->getTaxRateShipping($order);

        $this->assertSame(
            $expected,
            [$order->getData('two_shipping_tax_rate_source'), $order->getData('two_shipping_tax_rate')],
            $case
        );
    }

    public static function placementRecords(): array
    {
        return [
            ['blank', 0.0, 0.00, ['declared', 0.0], 'a recorded 0% is stored as 0'],
            ['populated', 25.0, 25.00, ['declared', 25.0], 'a recorded rate is stored as is'],
            ['blank', null, 25.00, ['none', null], 'no rate, fallback blank: none, no rate'],
            ['populated', null, 25.00, ['none', 25.0], 'no rate, fallback populated: none, with its rate'],
        ];
    }

    /**
     * After placement the record decides, never the configuration or the
     * tax rows as they read now; Magento saves no 0% shipping rate, so a
     * recorded 0% reads back as no tax row at all. An order placed before
     * the record existed resolves as at placement.
     *
     * @param float|string $expected
     * @dataProvider recordedCases
     */
    public function testTheRecordedCaseOutlivesAConfigChange(
        ?string $source,
        ?float $recordedPercent,
        string $controlNow,
        float $tax,
        $expected,
        string $case,
        bool $reconcile = true
    ): void {
        [$classByStore, $fallbackByStore] = self::CONTROLS[$controlNow];
        $order = $this->entity([
            'shipping_address' => new DataObject(),
            'shipping_tax_amount' => $tax,
            'two_shipping_tax_rate_source' => $source,
            'two_shipping_tax_rate' => $recordedPercent,
        ]);
        $invoice = $this->entity(['order' => $order, 'shipping_tax_amount' => $tax]);

        try {
            $actual = $this->orderService(null, $classByStore, $fallbackByStore)->getTaxRateShipping($invoice, $reconcile);
        } catch (LocalizedException $e) {
            $actual = $e->getMessage();
        }

        $this->assertSame($expected, $actual, $case);
    }

    public static function recordedCases(): array
    {
        return [
            ['declared', 0.0, 'populated', 0.00, 0.0, '0% at placement, fallback populated since: 0%, not resolved'],
            ['declared', 0.0, 'blank', 0.00, 0.0, '0% at placement, fallback blank: 0%'],
            ['none', null, 'populated', 25.00, 0.0, 'no rate with the fallback blank at placement, populated since: 0% as charged'],
            ['none', 25.0, 'blank', 25.00, 0.25, 'no rate, fallback rate recorded, fallback blanked since: the recorded rate'],
            ['none', 25.0, 'populated at 0%', 25.00, 0.25, 'no rate, fallback rate recorded, class changed since: the recorded rate'],
            ['none', 25.0, 'populated', 19.00, self::REFUSED, 'recorded fallback rate, tax off it: refused'],
            ['none', 25.0, 'blank', 19.00, 0.25, 'recorded fallback rate on a refund: relayed without the check', false],
            [null, null, 'populated', 25.00, 0.25, 'legacy order, nothing recorded: resolved as at placement'],
            [null, null, 'blank', 25.00, 0.0, 'legacy order, fallback blank: 0% as charged'],
        ];
    }

    /**
     * An invoice carries no shipping discount of its own: the check uses the
     * order's, as ComposeCapture composes the line.
     */
    public function testAnInvoiceIsCheckedOnTheOrdersShippingDiscount(): void
    {
        $order = $this->entity(['shipping_discount_amount' => 20.00, 'shipping_address' => new DataObject()]);
        $invoice = $this->entity(['order' => $order, 'shipping_tax_amount' => 20.00]);

        $this->assertSame(0.25, $this->orderService(null, [1 => 5], [1 => true])->getTaxRateShipping($invoice));
    }

    /**
     * getRateRequest() gets core's quote-time arguments: shipping and
     * billing separately, the store, the current tax class of the order-time
     * group, and the customer id. sales_order stores no customer tax class,
     * so a class edit on that group since placement still changes the rate.
     *
     * @dataProvider customerTaxClassCases
     */
    public function testCoreClassResolutionUsesTheOrderTimeGroupsCurrentClass(
        bool $isCreditmemo,
        bool $isVirtual,
        int $groupId,
        ?int $customerId,
        float $shippingTax,
        ?int $expectedCustomerClass,
        ?int $expectedCustomerId,
        float $expected,
        string $case
    ): void {
        $shippingAddress = $isVirtual ? null : new DataObject(['country_id' => 'NO']);
        $billingAddress = new DataObject(['country_id' => 'SE']);
        $order = $this->entity([
            'shipping_amount' => 12.00 / $expected,
            'shipping_tax_amount' => 12.00,
            'shipping_address' => $shippingAddress,
            'billing_address' => $billingAddress,
            'is_virtual' => $isVirtual,
            'customer_id' => $customerId,
            'customer_group_id' => $groupId,
        ]);
        $entity = $isCreditmemo ? $this->entity([
            'order' => $order,
            'shipping_amount' => $shippingTax / $expected,
            'shipping_tax_amount' => $shippingTax,
        ]) : $order;

        $rate = $this->orderService(null, [1 => 5])->getTaxRateShipping($entity);

        $this->assertSame($expected, $rate, $case);
        $this->assertSame(
            [[$shippingAddress ?? $billingAddress, $billingAddress, $expectedCustomerClass, 1, $expectedCustomerId]],
            $this->rateRequests,
            $case
        );
    }

    public static function customerTaxClassCases(): array
    {
        return [
            [false, false, 0, null, 25.00, 3, null, 0.25, 'guest: NOT LOGGED IN class'],
            [false, false, 2, 42, 12.00, 10, 42, 0.12, 'capture after a customer group change: order-time group'],
            [false, false, 2, 43, 12.00, 10, 43, 0.12, 'deleted customer: order-time group, no customer lookup'],
            [false, false, 99, 42, 20.00, null, 42, 0.20, 'order group deleted, customer alive: current group class'],
            [false, false, 99, 43, 25.00, null, null, 0.25, 'order group and customer deleted: NOT LOGGED IN class'],
            [true, false, 2, 42, 12.00, 10, 42, 0.12, 'refund after a customer group change: order-time group'],
            [true, false, 2, 42, 6.00, 10, 42, 0.12, 'partial credit memo: order-time rate on the refunded shipping'],
            [false, true, 2, 42, 12.00, 10, 42, 0.12, 'virtual order: billing stands in for shipping'],
        ];
    }
}
