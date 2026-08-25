<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsItemInterface;
use Magento\Tax\Api\OrderTaxManagementInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;

/**
 * TWO-25503: the shipping line's tax_rate is whatever Magento's tax engine
 * declared for that line — never tax/net. A derived quotient lands on rates
 * no tax rule declares as soon as rounding, mixed rates or a shipping
 * discount are involved, and Two validates the declared rate against the
 * line's own amounts.
 *
 * Where nothing is declared: an untaxed line is 0% (a statement, not a
 * guess); a taxed line falls back to the merchant's declared default rate,
 * and refuses the order when that is unset.
 */
class ShippingTaxRateTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /**
     * @param float|null $declaredPercent percent Magento declares for shipping, null for none
     * @param float|null $fallbackPercent merchant-configured default, null for unset
     * @return Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private function orderService(?float $declaredPercent, ?float $fallbackPercent = null)
    {
        $orderService = $this->getMockForAbstractClass(Order::class, [], '', false);

        $this->logRepository = $this->createMock(LogRepository::class);
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getDefaultShippingTaxRate')->willReturn($fallbackPercent);
        $orderService->configRepository = $configRepository;

        $this->setProperty($orderService, 'logRepository', $this->logRepository);
        $this->setProperty(
            $orderService,
            'orderTaxManagement',
            $this->taxManagement($declaredPercent)
        );

        return $orderService;
    }

    private function setProperty(object $target, string $name, $value): void
    {
        $property = new \ReflectionProperty(Order::class, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
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

    private function entity(float $shippingTax, float $shippingAmount, float $shippingInclTax): object
    {
        return new class ($shippingTax, $shippingAmount, $shippingInclTax) {
            private $shippingTax;
            private $shippingAmount;
            private $shippingInclTax;

            public function __construct(float $shippingTax, float $shippingAmount, float $shippingInclTax)
            {
                $this->shippingTax = $shippingTax;
                $this->shippingAmount = $shippingAmount;
                $this->shippingInclTax = $shippingInclTax;
            }

            public function getShippingTaxAmount(): float
            {
                return $this->shippingTax;
            }

            public function getShippingAmount(): float
            {
                return $this->shippingAmount;
            }

            public function getShippingInclTax(): float
            {
                return $this->shippingInclTax;
            }

            public function getId(): int
            {
                return 7;
            }

            public function getStoreId(): int
            {
                return 1;
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
        $rate = $this->orderService(25.0)->getTaxRateShipping($this->entity(24.00, 100.00, 124.00));

        $this->assertSame(0.25, $rate);
    }

    public function testCombinedDeclaredRatesSumToTheRateTheBuyerPaid(): void
    {
        // Given state + city tax on shipping; When composing; Then one rate.
        $orderService = $this->getMockForAbstractClass(Order::class, [], '', false);
        $orderService->configRepository = $this->createMock(ConfigRepository::class);
        $this->setProperty($orderService, 'logRepository', $this->createMock(LogRepository::class));
        $this->setProperty(
            $orderService,
            'orderTaxManagement',
            $this->taxManagementFor([$this->taxItem('shipping', [6.0, 2.5])])
        );

        $this->assertSame(0.085, $orderService->getTaxRateShipping($this->entity(8.50, 100.00, 108.50)));
    }

    public function testUntaxedShippingNeedsNoDeclarationAndNoFallback(): void
    {
        // Given no declared rate and no shipping tax; When composing; Then
        // 0% — no fallback consulted, no refusal.
        $rate = $this->orderService(null)->getTaxRateShipping($this->entity(0.00, 100.00, 100.00));

        $this->assertSame(0.0, $rate);
    }

    public function testTaxedShippingWithNoDeclarationUsesTheConfiguredFallback(): void
    {
        $orderService = $this->orderService(null, 25.0);
        $this->logRepository->expects($this->once())->method('addDebugLog');

        $this->assertSame(0.25, $orderService->getTaxRateShipping($this->entity(25.00, 100.00, 125.00)));
    }

    public function testTaxedShippingWithNoDeclarationAndNoFallbackRefusesTheOrder(): void
    {
        $orderService = $this->orderService(null, null);
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->expectException(LocalizedException::class);
        $orderService->getTaxRateShipping($this->entity(25.00, 100.00, 125.00));
    }

    /**
     * A merchant who declares 0% has made a statement; the getter must relay
     * it rather than treating it as "nothing configured" and refusing.
     */
    public function testAConfiguredZeroFallbackIsADeclarationNotAnAbsence(): void
    {
        $rate = $this->orderService(null, 0.0)->getTaxRateShipping($this->entity(25.00, 100.00, 125.00));

        $this->assertSame(0.0, $rate);
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
        $orderService = $this->getMockForAbstractClass(Order::class, [], '', false);
        $configRepository = $this->createMock(ConfigRepository::class);
        // Refuse rather than fall back, so a rate that fails to come off the
        // extension attribute cannot be masked by the configured default.
        $configRepository->method('getDefaultShippingTaxRate')->willReturn(null);
        $orderService->configRepository = $configRepository;
        $this->setProperty($orderService, 'logRepository', $this->createMock(LogRepository::class));

        $taxManagement = $this->createMock(OrderTaxManagementInterface::class);
        $taxManagement->expects($this->never())->method('getOrderTaxDetails');
        $this->setProperty($orderService, 'orderTaxManagement', $taxManagement);

        $entity = $this->unsavedEntity(25.00, $itemAppliedTaxes);

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

    /**
     * An order whose id is null and whose extension attribute carries the
     * shipping-typed entry from quote->order conversion.
     */
    private function unsavedEntity(float $shippingTax, array $itemAppliedTaxes): object
    {
        return new class ($shippingTax, $itemAppliedTaxes) {
            private $shippingTax;
            private $extensionAttributes;

            public function __construct(float $shippingTax, array $itemAppliedTaxes)
            {
                $this->shippingTax = $shippingTax;
                $this->extensionAttributes = new class ($itemAppliedTaxes) {
                    private $itemAppliedTaxes;

                    public function __construct(array $itemAppliedTaxes)
                    {
                        $this->itemAppliedTaxes = $itemAppliedTaxes;
                    }

                    public function getItemAppliedTaxes(): array
                    {
                        return $this->itemAppliedTaxes;
                    }
                };
            }

            public function getShippingTaxAmount(): float
            {
                return $this->shippingTax;
            }

            public function getExtensionAttributes(): object
            {
                return $this->extensionAttributes;
            }

            public function getId(): ?int
            {
                return null;
            }

            public function getStoreId(): int
            {
                return 1;
            }

            public function getIncrementId(): string
            {
                return '100000008';
            }
        };
    }
}
