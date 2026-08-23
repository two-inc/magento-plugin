<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee\Provider;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\Provider\AmastyExtraFee;

/**
 * ComposeOrder hands this provider $payment->getOrder() — the in-memory
 * object from the current placement transaction, never loaded through
 * OrderRepositoryInterface — so Amasty\Extrafee\Plugin\Order\OrderRepository
 * (which only populates the amextrafee_fee_amount/amextrafee_tax_amount
 * extension attributes on afterGet/afterGetList) never ran on it. Every
 * itemization scenario here goes through a reload via a stub repository to
 * cover that. Also covers: no Amasty getters generated (not installed),
 * order not yet persisted, and the reload finding nothing.
 */
class AmastyExtraFeeTest extends TestCase
{
    private function providerWithRepository(OrderRepositoryInterface $orderRepository): AmastyExtraFee
    {
        return new AmastyExtraFee($orderRepository);
    }

    private function unpersistedOrder(): OrderModel
    {
        return new OrderModel();
    }

    private function persistedOrder(int $entityId): OrderModel
    {
        $order = new OrderModel();
        $order->setEntityId($entityId);
        return $order;
    }

    private function orderWithExtensionAttributes(int $entityId, $extensionAttributes): OrderModel
    {
        $order = $this->persistedOrder($entityId);
        $order->setExtensionAttributes($extensionAttributes);
        return $order;
    }

    private function amastyExtensionAttributes(float $feeAmount, float $taxAmount)
    {
        return new class ($feeAmount, $taxAmount) {
            private float $feeAmount;
            private float $taxAmount;

            public function __construct(float $feeAmount, float $taxAmount)
            {
                $this->feeAmount = $feeAmount;
                $this->taxAmount = $taxAmount;
            }

            public function getAmextrafeeFeeAmount(): float
            {
                return $this->feeAmount;
            }

            public function getAmextrafeeTaxAmount(): float
            {
                return $this->taxAmount;
            }
        };
    }

    /** Stub repository that returns the given order for any get() call. */
    private function repositoryReturning(OrderInterface $order): OrderRepositoryInterface
    {
        return new class ($order) implements OrderRepositoryInterface {
            private OrderInterface $order;

            public function __construct(OrderInterface $order)
            {
                $this->order = $order;
            }

            public function get($id): OrderInterface
            {
                return $this->order;
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                throw new \LogicException('not used by this provider');
            }

            public function save(OrderInterface $entity): OrderInterface
            {
                throw new \LogicException('not used by this provider');
            }

            public function delete(OrderInterface $entity): bool
            {
                throw new \LogicException('not used by this provider');
            }
        };
    }

    /** Stub repository whose get() always misses, as for an unpersisted/deleted order. */
    private function repositoryFindingNothing(): OrderRepositoryInterface
    {
        return new class implements OrderRepositoryInterface {
            public function get($id): OrderInterface
            {
                throw new NoSuchEntityException(__('No such order.'));
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                throw new \LogicException('not used by this provider');
            }

            public function save(OrderInterface $entity): OrderInterface
            {
                throw new \LogicException('not used by this provider');
            }

            public function delete(OrderInterface $entity): bool
            {
                throw new \LogicException('not used by this provider');
            }
        };
    }

    public function testReturnsNoLineForNonOrderEntity(): void
    {
        $provider = $this->providerWithRepository($this->repositoryFindingNothing());

        $this->assertSame(
            [],
            $provider->getFeeLines(new Creditmemo()),
            'a Creditmemo/Invoice entity is out of scope for this provider'
        );
    }

    public function testReturnsNoLineForAnUnpersistedOrder(): void
    {
        $provider = $this->providerWithRepository($this->repositoryFindingNothing());

        $this->assertSame(
            [],
            $provider->getFeeLines($this->unpersistedOrder()),
            'no entity ID means nothing to reload — never reach the repository'
        );
    }

    public function testReturnsNoLineWhenTheReloadFindsNoOrder(): void
    {
        $provider = $this->providerWithRepository($this->repositoryFindingNothing());

        $this->assertSame(
            [],
            $provider->getFeeLines($this->persistedOrder(1)),
            'NoSuchEntityException on reload means nothing to itemize'
        );
    }

    /**
     * @dataProvider noLineScenarioProvider
     */
    public function testReturnsNoLineWhen($extensionAttributes, string $description): void
    {
        $reloadedOrder = $this->orderWithExtensionAttributes(1, $extensionAttributes);
        $provider = $this->providerWithRepository($this->repositoryReturning($reloadedOrder));

        $this->assertSame([], $provider->getFeeLines($this->persistedOrder(1)), $description);
    }

    /** @return array<string, array{mixed, string}> */
    public function noLineScenarioProvider(): array
    {
        return [
            'reloaded order has no extension attributes at all' => [
                null,
                'getExtensionAttributes() returning null means nothing to itemize',
            ],
            'reloaded order extension attributes lack the Amasty getters' => [
                new class {
                },
                'no amasty/module-extra-fee installed — the getters were never generated',
            ],
            'reloaded order has the Amasty getters but no fee was selected' => [
                $this->amastyExtensionAttributes(0.0, 0.0),
                'a zero fee amount means this order has no Amasty fee, not a fee worth £0',
            ],
        ];
    }

    /**
     * @dataProvider feeAmountScenarioProvider
     */
    public function testItemizesFeeAmount(float $netAmount, float $taxAmount, array $expectedLine, string $description): void
    {
        $reloadedOrder = $this->orderWithExtensionAttributes(
            1,
            $this->amastyExtensionAttributes($netAmount, $taxAmount)
        );
        $provider = $this->providerWithRepository($this->repositoryReturning($reloadedOrder));

        $this->assertSame([$expectedLine], $provider->getFeeLines($this->persistedOrder(1)), $description);
    }

    /** @return array<string, array{float, float, array, string}> */
    public function feeAmountScenarioProvider(): array
    {
        return [
            'fee taxed at 20%' => [
                5.99,
                1.198,
                [
                    'order_item_id' => 'amasty_extrafee',
                    'name' => 'Amasty Fee',
                    'description' => 'Amasty Fee',
                    'type' => 'OTHER',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => '7.19',
                    'net_amount' => '5.99',
                    'tax_amount' => '1.20',
                    'discount_amount' => '0.00',
                    'tax_rate' => '0.200000',
                    'tax_class_name' => 'VAT 20.00%',
                    'unit_price' => '5.990000',
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ],
                'the exact case that reached checkout-api rejected: a taxed Amasty fee must land as a real line, not vanish from line_items',
            ],
            'fee with no tax' => [
                10.00,
                0.0,
                [
                    'order_item_id' => 'amasty_extrafee',
                    'name' => 'Amasty Fee',
                    'description' => 'Amasty Fee',
                    'type' => 'OTHER',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => '10.00',
                    'net_amount' => '10.00',
                    'tax_amount' => '0.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '0.000000',
                    'tax_class_name' => 'VAT 0.00%',
                    'unit_price' => '10.000000',
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ],
                'an untaxed fee still itemizes correctly with a 0% rate, not a division-by-zero',
            ],
        ];
    }
}
