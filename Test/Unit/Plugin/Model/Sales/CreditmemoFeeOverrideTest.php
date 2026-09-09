<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Model\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Model\Sales\CreditmemoFeeOverride;
use Two\Gateway\Service\Order\OtherChargesResolver;

class CreditmemoFeeOverrideTest extends TestCase
{
    private const SURCHARGE_CHARGED = 5.0;

    private const OTHER_CHARGES_CHARGED = 10.0;

    private function request(array $params, string $action = 'save')
    {
        return new class ($params, $action) implements \Magento\Framework\App\RequestInterface {
            private $p;
            private $a;
            public function __construct($p, $a)
            {
                $this->p = $p;
                $this->a = $a;
            }
            public function getParam($key, $default = null)
            {
                return $this->p[$key] ?? $default;
            }
            public function getControllerName()
            {
                return 'order_creditmemo';
            }
            public function getActionName()
            {
                return $this->a;
            }
        };
    }

    private function format()
    {
        return new class implements \Magento\Framework\Locale\FormatInterface {
            public function getNumber($value)
            {
                return (float)str_replace(',', '.', (string)$value);
            }
            public function getPriceFormat($localeCode = null, $currencyCode = null)
            {
                return [];
            }
        };
    }

    /**
     * No order column records a third-party fee, so the cap comes from the
     * resolver's derived residual less what earlier memos took.
     */
    private function resolver(?array $residual, array $priorMemos = []): OtherChargesResolver
    {
        $resolver = $this->getMockBuilder(OtherChargesResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['forOrder'])
            ->getMock();
        $resolver->method('forOrder')->willReturn($residual);
        $this->priorMemos = $priorMemos;

        return $resolver;
    }

    /** @var array */
    private $priorMemos = [];

    private function plugin(array $post, string $action = 'save', ?array $residual = null): CreditmemoFeeOverride
    {
        return new CreditmemoFeeOverride(
            $this->request(['creditmemo' => $post], $action),
            $this->format(),
            $this->resolver($residual ?? ['net_amount' => (string)self::OTHER_CHARGES_CHARGED])
        );
    }

    private function creditmemo(float $surchargeRefunded = 0.0): Creditmemo
    {
        $order = new Order();
        $order->setData('two_surcharge_amount', self::SURCHARGE_CHARGED);
        $order->setData('two_surcharge_refunded', $surchargeRefunded);
        $order->setCreditmemosCollection($this->priorMemos);

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);

        return $creditmemo;
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testATypedValueIsStampedOrRefusedWithItsReason(
        string $field,
        $raw,
        ?float $expected,
        ?string $expectedMessage,
        string $description
    ): void {
        $plugin = $this->plugin([$field => $raw]);
        $creditmemo = $this->creditmemo();

        if ($expectedMessage !== null) {
            try {
                $plugin->beforeCollectTotals($creditmemo);
                $this->fail('expected a LocalizedException: ' . $description);
            } catch (LocalizedException $e) {
                $this->assertStringContainsString($expectedMessage, $e->getMessage(), $description);
                $this->assertNull($creditmemo->getData($field), 'nothing stamped: ' . $description);
            }
            return;
        }

        $plugin->beforeCollectTotals($creditmemo);

        $this->assertEqualsWithDelta($expected, (float)$creditmemo->getData($field), 0.0001, $description);
    }

    public static function fieldProvider(): array
    {
        return [
            [
                'two_surcharge_amount',
                '',
                0.0,
                null,
                'a cleared surcharge field is an explicit 0, not a fall back to the default',
            ],
            ['two_surcharge_amount', '2,50', 2.5, null, 'an nl_NL decimal comma is accepted'],
            [
                'two_surcharge_amount',
                '999',
                null,
                'Surcharge refund (',
                'over the surcharge cap, refused in the surcharge\'s own words',
            ],
            [
                'two_other_charges_amount',
                '',
                0.0,
                null,
                'a cleared other-charges field is an explicit 0',
            ],
            ['two_other_charges_amount', '7,25', 7.25, null, 'a partial third-party fee refund'],
            [
                'two_other_charges_amount',
                "\xc2\xa07,25",
                7.25,
                null,
                'an NBSP left by a currency paste is trimmed, not parsed as 0',
            ],
            [
                'two_other_charges_amount',
                (string)self::OTHER_CHARGES_CHARGED,
                self::OTHER_CHARGES_CHARGED,
                null,
                'exactly the whole charge is refundable',
            ],
            [
                'two_other_charges_amount',
                '10.01',
                10.01,
                null,
                'a cent over sits inside the display-rounding tolerance',
            ],
            [
                'two_other_charges_amount',
                '10.02',
                null,
                'Other charges refund (',
                'past the tolerance the merchant is told, not silently capped',
            ],
            [
                'two_other_charges_amount',
                '-1',
                null,
                'Other charges refund cannot be negative.',
                'a negative other-charges refund is refused',
            ],
            [
                'two_other_charges_amount',
                'abc',
                null,
                'Other charges refund must be a valid amount',
                'an unparseable value is named rather than silently becoming 0',
            ],
        ];
    }

    /**
     * One post carries both fields; both are stamped, so the surcharge cannot
     * shadow the fee or vice versa.
     */
    public function testBothFeeFieldsAreStampedFromOnePost(): void
    {
        $plugin = $this->plugin([
            'two_surcharge_amount' => '1.25',
            'two_other_charges_amount' => '3.50',
        ]);
        $creditmemo = $this->creditmemo();

        $plugin->beforeCollectTotals($creditmemo);

        $this->assertEqualsWithDelta(1.25, (float)$creditmemo->getData('two_surcharge_amount'), 0.0001);
        $this->assertEqualsWithDelta(3.5, (float)$creditmemo->getData('two_other_charges_amount'), 0.0001);
    }

    /**
     * The cap is what the charge has LEFT, so an earlier memo's share is not
     * refundable twice.
     */
    public function testWhatAnEarlierMemoTookIsOutsideTheCap(): void
    {
        $prior = new Creditmemo();
        $prior->setData('id', 1);
        $prior->setData('two_other_charges_amount', 6.0);

        $plugin = new CreditmemoFeeOverride(
            $this->request(['creditmemo' => ['two_other_charges_amount' => '5.00']]),
            $this->format(),
            $this->resolver(['net_amount' => (string)self::OTHER_CHARGES_CHARGED], [$prior])
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('exceeds the remaining refundable other charges (4)');

        $plugin->beforeCollectTotals($this->creditmemo());
    }

    /**
     * Scoped to the creditmemo write actions, so a `creditmemo[*]` query string
     * cannot reach the totals of a creditmemo some other controller built.
     *
     * @dataProvider actionProvider
     */
    public function testOnlyTheCreditmemoWriteActionsAreRead(
        string $action,
        bool $expectStamped,
        string $description
    ): void {
        $plugin = $this->plugin(['two_other_charges_amount' => '3.00'], $action);
        $creditmemo = $this->creditmemo();

        $plugin->beforeCollectTotals($creditmemo);

        if ($expectStamped) {
            $this->assertEqualsWithDelta(
                3.0,
                (float)$creditmemo->getData('two_other_charges_amount'),
                0.0001,
                $description
            );
            return;
        }

        $this->assertNull($creditmemo->getData('two_other_charges_amount'), $description);
    }

    public static function actionProvider(): array
    {
        return [
            ['save', true, 'the save action'],
            ['updateQty', true, 'the recalc round trip'],
            ['new', false, 'the create form itself carries no merchant instruction yet'],
        ];
    }

    /**
     * With no residual there is no charge to bound the typed value against, so
     * the value passes to the collector, which has the order in hand.
     */
    public function testAnUnresolvableResidualDoesNotRefuseTheValue(): void
    {
        $plugin = $this->plugin(['two_other_charges_amount' => '3.00'], 'save', null);
        $creditmemo = $this->creditmemo();

        $plugin->beforeCollectTotals($creditmemo);

        $this->assertEqualsWithDelta(3.0, (float)$creditmemo->getData('two_other_charges_amount'), 0.0001);
    }
}
