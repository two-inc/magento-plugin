<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Model\Config\Backend\TaxCodeMap;
use Two\Gateway\Model\OrderTenders;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingTotals;
use Two\Gateway\Service\Order\TenderReader;
use Two\Gateway\Service\Order\TwoOrderView;
use Two\Gateway\Test\Unit\Service\Order\Doubles\PostprocessorFactory;

/**
 * The order postprocessing runtime brought level with the other platforms
 * (TWO-26309): tenders, the restored and added shop-match checks, the
 * refusal pass-through, the redacted change log, Two's view of the order in
 * the context, and the tax code map's read path.
 */
class OrderPostprocessingParityTest extends TestCase
{
    use PostprocessorFactory;

    /** @var array<int, array{0: string, 1: mixed}> */
    private $errorLog = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private $debugLog = [];

    /**
     * @dataProvider tenderEntries
     */
    public function testTendersAreNormalised(array $declared, array $expected, bool $logged, string $description): void
    {
        $provider = $this->createMock(\Two\Gateway\Api\OrderTendersInterface::class);
        $provider->method('collect')->willReturn($declared);
        $reader = new TenderReader($provider, $this->log());

        $this->assertSame($expected, $reader->read(new Order()), $description);
        $this->assertSame($logged, $this->errorLog !== [], $description . ': logged');
        $this->assertStringNotContainsString('GC-SECRET', (string)json_encode($this->errorLog), $description . ': never the label');
    }

    public static function tenderEntries(): array
    {
        $card = ['type' => 'gift_card', 'label' => 'Gift card', 'amount' => '10.004', 'source' => 'x'];

        return [
            [[$card], [['type' => 'gift_card', 'label' => 'Gift card', 'amount' => '10.00', 'source' => 'x']], false, 'rounded to 2dp'],
            [[['amount' => 0] + $card], [], false, 'zero is skipped, unlogged'],
            [[['amount' => -5, 'label' => 'GC-SECRET'] + $card], [], true, 'negative is dropped and logged'],
            [[['amount' => 'abc', 'label' => 'GC-SECRET'] + $card], [], true, 'not a number is dropped and logged'],
            [[['type' => 'points'] + $card], [['type' => 'other', 'label' => 'Gift card', 'amount' => '10.00', 'source' => 'x']], false, 'an unknown type is other'],
            [[['source' => null] + $card], [['type' => 'gift_card', 'label' => 'Gift card', 'amount' => '10.00', 'source' => 'plugin']], false, 'no source: plugin'],
        ];
    }

    /**
     * @dataProvider adobeFields
     */
    public function testAdobeCommerceTendersAreReadByEntity(string $class, array $data, array $types, string $description): void
    {
        $entity = $class === Quote::class ? new class extends Quote {
            /** @var array */
            private $values = [];

            public function setData($key, $value = null)
            {
                $this->values[$key] = $value;
                return $this;
            }

            public function getData($key = '', $index = null)
            {
                return $this->values[$key] ?? null;
            }
        } : new $class();
        foreach ($data as $field => $amount) {
            $entity->setData($field, $amount);
        }

        $this->assertSame($types, array_column((new OrderTenders())->collect($entity), 'type'), $description);
    }

    public static function adobeFields(): array
    {
        $all = ['gift_cards_amount' => 5, 'customer_balance_amount' => 6, 'reward_currency_amount' => 7];

        return [
            [Order::class, [], [], 'Open Source: no tender fields, no tenders'],
            [Order::class, $all, ['gift_card', 'store_credit', 'other'], 'an order\'s gift card, store credit and reward points'],
            [Order\Invoice::class, ['gift_cards_amount' => 5], ['gift_card'], 'an invoice\'s share'],
            [Order\Creditmemo::class, ['customer_balance_amount' => 6], ['store_credit'], 'a credit memo\'s share'],
            [Quote::class, ['gift_cards_amount_used' => 5, 'customer_balance_amount_used' => 6], ['gift_card', 'store_credit'], 'a quote reads its own fields'],
            [Quote::class, ['gift_cards_amount' => 5], [], 'and not the order\'s'],
        ];
    }

    /**
     * @dataProvider amounts
     */
    public function testOrderAmountsDeclareTheTendersAsPrepaid(float $grand, float $tax, float $tendered, array $expected, string $description): void
    {
        $composer = $this->getMockBuilder(ComposeOrder::class)->disableOriginalConstructor()->onlyMethods([])->getMock();

        $this->assertSame($expected, $composer->orderAmounts($grand, $tax, $tendered), $description);
    }

    public static function amounts(): array
    {
        return [
            [150.0, 21.0, 0.0, ['gross_amount' => '150.00', 'net_amount' => '129.00', 'tax_amount' => '21.00'], 'no tender: as before'],
            [140.0, 21.0, 10.0, ['gross_amount' => '140.00', 'net_amount' => '129.00', 'tax_amount' => '21.00',
                'invoice_amount' => '150.00', 'payable_amount' => '140.00', 'prepaid_amount' => '10.00'], 'a gift card'],
            [0.0, 21.0, 150.0, ['gross_amount' => '0.00', 'net_amount' => '129.00', 'tax_amount' => '21.00',
                'invoice_amount' => '150.00', 'payable_amount' => '0.00', 'prepaid_amount' => '150.00'], 'fully covered'],
            // No tenders: byte for byte what staging's builders send for amounts below a cent.
            [10.005, 1.004, 0.0, ['gross_amount' => '10.01', 'net_amount' => '9.00', 'tax_amount' => '1.00'], 'staging: sub-cent grand and tax'],
            [99.995, 17.354, 0.0, ['gross_amount' => '100.00', 'net_amount' => '82.64', 'tax_amount' => '17.35'], 'staging: a rounding carry'],
            [0.125, 0.0249, 0.0, ['gross_amount' => '0.13', 'net_amount' => '0.10', 'tax_amount' => '0.02'], 'staging: small amounts'],
            [1234.565, 214.2649, 0.0, ['gross_amount' => '1234.57', 'net_amount' => '1020.30', 'tax_amount' => '214.26'], 'staging: large amounts'],
        ];
    }

    /**
     * The per-line declared-rate check, restored as a shop-match check: a
     * composed line whose tax does not follow its rate refuses create and
     * update while the result carries it unchanged.
     *
     * @dataProvider lineRateCases
     */
    public function testAMistaxedComposedLineIsRefusedWhileCarried(string $requestType, bool $edited, ?string $refusal, string $description): void
    {
        $payload = self::payload();
        $payload['line_items'][0]['tax_amount'] = '25.00';
        $payload['line_items'][0]['gross_amount'] = '125.00';
        $result = $payload;
        if ($edited) {
            $result['line_items'][0]['tax_amount'] = '21.00';
        }

        $this->assertCheck($refusal, fn () => $this->checks()->checkLines($result, $payload, self::context($requestType)), $description);
    }

    public static function lineRateCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_CREATE, false, 'This order could not be placed. Please contact the merchant.', 'create: refused'],
            [Hook::REQUEST_ORDER_UPDATE, false, 'A line\'s tax does not match the tax rate the shop declared for it.', 'update: refused, naming the check'],
            [Hook::REQUEST_ORDER_CREATE, true, null, 'the subscriber changed the line: theirs'],
            [Hook::REQUEST_CAPTURE, false, null, 'capture: not a declared-rate request'],
            [Hook::REQUEST_REFUND, false, null, 'refund: never'],
        ];
    }

    /**
     * The refund's side of the shipping tax fallback (TWO-26309): with the
     * fallback recorded at placement, the shipping a memo refunds must carry
     * the tax the recorded rate gives.
     *
     * @dataProvider refundShippingCases
     */
    public function testARefundsShippingFollowsTheRateTheFallbackGaveAtPlacement(
        string $source,
        ?float $rate,
        float $refundedTax,
        bool $edited,
        ?string $refusal,
        string $description,
        float $orderShipping = 29.00,
        float $discount = 0.00,
        float $refundedShipping = 29.00
    ): void {
        $context = self::context(Hook::REQUEST_REFUND);
        $context['order']->setData('two_shipping_tax_rate_source', $source);
        $context['order']->setData('two_shipping_tax_rate', $rate);
        $context['order']->setData('shipping_amount', $orderShipping);
        $context['order']->setData('shipping_discount_amount', $discount);
        $context['creditmemo']->setData('shipping_amount', $refundedShipping);
        $context['creditmemo']->setData('shipping_tax_amount', $refundedTax);
        $payload = ['amount' => '29.00', 'line_items' => [self::payload()['line_items'][1]]];
        $result = $payload;
        if ($edited) {
            $result['line_items'][0]['tax_amount'] = '6.09';
        }

        $this->assertCheck($refusal, fn () => $this->checks()->check($result, $payload, $context), $description);
    }

    public static function refundShippingCases(): array
    {
        $refused = 'Shipping tax on this order does not match the rate of the Tax Class for Shipping '
            . '(Stores > Configuration > Sales > Tax > Tax Classes), which applies because Magento recorded no shipping tax rate.';

        return [
            ['none', 21.0, 6.09, false, null, 'the refunded tax follows the placement rate'],
            ['none', 21.0, 0.00, false, $refused, 'it does not: refused'],
            ['none', 21.0, 0.00, true, null, 'the subscriber changed the line: theirs'],
            ['declared', 0.0, 0.00, false, null, 'Magento recorded the rate: never the fallback\'s check'],
            ['none', null, 0.00, false, null, 'the fallback was blank at placement: nothing to check'],
            // Shipping 20 at 25%, a 10 shipping discount: Magento keeps that discount on the order only.
            ['none', 25.0, 1.25, false, null, 'half the discounted shipping refunded: half the discount', 20.00, 10.00, 10.00],
            ['none', 25.0, 2.50, false, null, 'all the discounted shipping refunded', 20.00, 10.00, 20.00],
            ['none', 25.0, 2.50, false, null, 'half the undiscounted shipping refunded', 20.00, 0.00, 10.00],
            ['none', 25.0, 5.00, false, null, 'all the undiscounted shipping refunded', 20.00, 0.00, 20.00],
            ['none', 25.0, 0.50, false, $refused, 'half the discounted shipping, with too little tax: refused', 20.00, 10.00, 10.00],
        ];
    }

    /**
     * The whole-order check: the lines against the order's totals plus its
     * tenders, refused on create and update, logged on intent, left out by
     * checkLines().
     *
     * @dataProvider wholeOrderCases
     */
    public function testTheLinesMustAddUpToTheTotalsPlusTenders(
        string $requestType,
        float $grand,
        float $giftCard,
        bool $wholeOrder,
        ?string $refusal,
        bool $logged,
        string $description
    ): void {
        $context = self::context($requestType, $grand, $giftCard);

        $this->assertCheck($refusal, fn () => $wholeOrder
            ? $this->checks()->check(self::payload(), self::payload(), $context)
            : $this->checks()->checkLines(self::payload(), self::payload(), $context), $description);
        $this->assertSame($logged, $this->errorLog !== [], $description . ': logged');
    }

    public static function wholeOrderCases(): array
    {
        $buyer = 'This order could not be placed. Please contact the merchant.';

        return [
            [Hook::REQUEST_ORDER_CREATE, 150.0, 0.0, true, null, false, 'the lines are the total'],
            [Hook::REQUEST_ORDER_CREATE, 140.0, 10.0, true, null, false, 'a gift card explains the gap'],
            [Hook::REQUEST_ORDER_CREATE, 140.0, 0.0, true, $buyer, true, 'an unexplained gap refuses a create'],
            [Hook::REQUEST_ORDER_UPDATE, 140.0, 0.0, true, 'The order lines do not add up to the order total.', true, 'and an update'],
            [Hook::REQUEST_ORDER_INTENT, 140.0, 0.0, true, null, true, 'the intent only logs it'],
            [Hook::REQUEST_ORDER_CREATE, 140.0, 0.0, false, null, false, 'checkLines(): not compared'],
            [Hook::REQUEST_ORDER_CREATE, 150.02, 0.0, true, null, false, 'rounding within tolerance'],
        ];
    }

    /**
     * The whole-order check on an edit counts the tenders the body declared
     * (TWO-26309): an order placed before tenders were declared is edited
     * with lines that add up to its grand total alone, as before, and passes.
     *
     * @dataProvider editedOrders
     */
    public function testAnEditIsCheckedAgainstTheTendersItDeclares(
        float $grand,
        float $giftCard,
        ?int $twoPrepaid,
        ?string $refusal,
        string $description
    ): void {
        $this->twoPrepaid = $twoPrepaid;
        $context = self::context(Hook::REQUEST_ORDER_UPDATE, $grand, $giftCard);

        $this->assertCheck($refusal, fn () => $this->checks()->check(self::payload(), self::payload(), $context), $description);
    }

    public static function editedOrders(): array
    {
        $refused = 'The order lines do not add up to the order total.';

        return [
            [150.0, 0.0, null, null, 'a new order, no tender'],
            [140.0, 10.0, null, null, 'a new order with a gift card: its lines carry the card'],
            [150.0, 0.0, 0, null, 'an order placed before, no tender'],
            // Placed before: the edit's lines add up to the grand total alone, 150 here, with a 10 card on the order.
            [150.0, 10.0, 0, null, 'an order placed before, with a gift card: lines to the grand total, accepted'],
            [140.0, 10.0, 0, $refused, 'placed before, lines that carry the card: no longer the grand total, refused'],
        ];
    }

    /**
     * Only the plugin's own refusal passes through; one a subscriber builds
     * itself is a fault in its code, logged by class and throw site only.
     *
     * @dataProvider thrownRefusals
     */
    public function testOnlyThePluginsOwnRefusalPassesThrough(bool $own, string $message, string $description): void
    {
        $log = $this->log();
        $subscriber = new class ($own ? $this->checks() : null) {
            private $checks;

            public function __construct($checks)
            {
                $this->checks = $checks;
            }

            public function afterProcess(Hook $subject, array $result, array $payload, array $context): array
            {
                if ($this->checks === null) {
                    throw new ShopMatchRefusedException(__('buyer secret 123'));
                }
                $result['line_items'][0]['tax_amount'] = '25.00';
                $this->checks->check($result, $result, $context);

                return $result;
            }
        };
        $postprocessor = $this->buildPostprocessor($this->hookChain($log, ['acme' => $subscriber]), $this->config(), $log);

        try {
            $postprocessor->process(Hook::REQUEST_ORDER_CREATE, self::payload(), self::context(Hook::REQUEST_ORDER_CREATE));
            $this->fail($description . ': not refused');
        } catch (LocalizedException $e) {
            $this->assertSame($message, $e->getMessage(), $description);
        }
        $this->assertStringNotContainsString('buyer secret', (string)json_encode($this->errorLog), $description . ': no message logged');
    }

    public static function thrownRefusals(): array
    {
        return [
            [true, 'This order could not be placed. Please contact the merchant.', 'the opt-in checks\' refusal'],
            [false, 'This order could not be placed. Please contact the merchant.', 'a hand-made refusal: HOOK_FAILED, buyer wording'],
        ];
    }

    public function testAHookFailureLogsTheThrowSiteNotTheMessage(): void
    {
        $log = $this->log();
        $subscriber = new class {
            public function afterProcess(Hook $subject, array $result): array
            {
                throw new RuntimeException('buyer secret 123');
            }
        };
        $postprocessor = $this->buildPostprocessor($this->hookChain($log, ['acme' => $subscriber]), $this->config(), $log);

        try {
            $postprocessor->process(Hook::REQUEST_ORDER_UPDATE, self::payload(), self::context(Hook::REQUEST_ORDER_UPDATE));
            $this->fail('not refused');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('TWO_ORDER_POSTPROCESSING_HOOK_FAILED', $e->getMessage());
        }
        $details = $this->errorLog[0][1]['details'];
        $this->assertSame(['exception', 'at'], array_keys($details));
        $this->assertSame(RuntimeException::class, $details['exception']);
        $this->assertStringContainsString('OrderPostprocessingParityTest.php:', $details['at']);
    }

    /**
     * @dataProvider diffs
     */
    public function testTheChangeLogIsCompactAndRedacted(array $diff, array $expected, int $count, string $description): void
    {
        $compact = $this->buildPostprocessor(null, $this->config(), $this->log())->compactDiff($diff);

        $this->assertSame($count, $compact['changes'], $description . ': changes');
        $this->assertSame($expected, array_slice($compact['diff'], 0, count($expected)), $description);
        $this->assertLessThanOrEqual(40, count($compact['diff']), $description . ': capped');
    }

    public static function diffs(): array
    {
        $long = str_repeat('x', 200);
        $many = array_map(static fn (int $i) => ['path' => "/line_items/$i/name", 'before' => 'a', 'after' => 'b'], range(0, 49));

        return [
            [[['path' => '/buyer/company/company_name', 'before' => 'Acme', 'after' => 'Other']],
                [['path' => '/buyer/company/company_name', 'before' => 'redacted', 'after' => 'redacted']], 1, 'buyer data is redacted'],
            [[['path' => '/gross_amount', 'before' => '1.00', 'after' => '2.00']],
                [['path' => '/gross_amount', 'before' => '1.00', 'after' => '2.00']], 1, 'an amount is kept'],
            [[['path' => '/partial/line_items/0/net_amount', 'before' => '1.00', 'after' => null]],
                [['path' => '/partial/line_items/0/net_amount', 'before' => '1.00', 'after' => null]], 1, 'a capture line is kept'],
            [[['path' => '/line_items/0/description', 'before' => $long, 'after' => 'x']],
                [['path' => '/line_items/0/description', 'before' => str_repeat('x', 120) . '...', 'after' => 'x']], 1, 'long strings are cut'],
            [$many, [['path' => '/line_items/0/name', 'before' => 'a', 'after' => 'b']], 50, 'at most 40 entries, all counted'],
        ];
    }

    /**
     * @dataProvider debugModes
     */
    public function testTheChangeIsLoggedOnlyInDebugModeAndNeverOnTheOrder(bool $debug, int $entries): void
    {
        $log = $this->log();
        $subscriber = new class {
            public function afterProcess(Hook $subject, array $result): array
            {
                $result['merchant_order_id'] = 'changed';

                return $result;
            }
        };
        $config = $this->config();
        $config->method('isDebugMode')->willReturn($debug);
        $order = self::context(Hook::REQUEST_ORDER_UPDATE)['order'];
        $this->buildPostprocessor($this->hookChain($log, ['acme' => $subscriber]), $config, $log)
            ->process(Hook::REQUEST_ORDER_UPDATE, self::payload(), self::context(Hook::REQUEST_ORDER_UPDATE));

        $changed = array_values(array_filter($this->debugLog, static fn ($e) => $e[0] === 'OrderPostprocessingChanged'));
        $this->assertCount($entries, $changed);
        $this->assertSame([], $order->getStatusHistories() ?? [], 'no order comment');
    }

    public static function debugModes(): array
    {
        return ['debug on' => [true, 1], 'debug off' => [false, 0]];
    }

    public function testRefundLinesAreMatchedToTheOrderLinesTheyCredit(): void
    {
        $line = static fn (string $id, string $name, string $gross) => ['id' => $id, 'name' => $name, 'description' => '', 'type' => 'PHYSICAL', 'tax_rate' => '0.21', 'gross_amount' => $gross];
        $refund = static fn (array $lines) => ['line_items' => array_map(static fn ($l) => ['prototype_id' => ''] + $l, $lines)];
        $two = [
            'line_items' => [$line('a', 'Mug', '10.00'), $line('b', 'Mug', '10.00'), $line('c', 'Hat', '5.00')],
            'refunds' => [
                ['line_items' => [['prototype_id' => 'a'] + $line('', 'Mug', '10.00')]],
                $refund([$line('', 'Mug', '10.00'), $line('', 'Hat', '5.00'), $line('', 'Scarf', '1.00')]),
            ],
        ];

        $refunds = TwoOrderView::refunds($two);

        $this->assertSame(['b', 'c', ''], array_column($refunds[1]['line_items'], 'prototype_id'), 'the unrefunded mug, the hat, nothing for the scarf');
        $this->assertSame(['a', 'b', 'c'], array_column(TwoOrderView::lines($two), 'id'));
        $this->assertNull(TwoOrderView::refunds(['line_items' => []]), 'no refunds key: null');
        $this->assertNull(TwoOrderView::lines(null), 'no response: null');
    }

    public function testTheContextCarriesTwosViewOfTheOrderWithAMerchantHandler(): void
    {
        $log = $this->log();
        $seen = new \ArrayObject();
        $subscriber = new class ($seen) {
            private $seen;

            public function __construct($seen)
            {
                $this->seen = $seen;
            }

            public function afterProcess(Hook $subject, array $result, array $payload, array $context): array
            {
                $this->seen['context'] = $context;

                return $result;
            }
        };
        $this->twoAdapter = $this->createMock(\Two\Gateway\Service\Api\Adapter::class);
        $this->twoAdapter->expects($this->once())->method('execute')
            ->with('/v1/order/two-1', [], 'GET')
            ->willReturn(['line_items' => [['id' => 'x']], 'refunds' => []]);
        $this->buildPostprocessor($this->hookChain($log, ['acme' => $subscriber]), $this->config(), $log)
            ->process(Hook::REQUEST_REFUND, ['amount' => '1.00', 'line_items' => []], array_merge(self::context(Hook::REQUEST_REFUND), ['endpoint' => '/v1/order/{id}/refund']));

        $context = $seen['context'];
        $this->assertSame([['id' => 'x']], $context['order_lines']);
        $this->assertSame([], $context['order_refunds']);
        $this->assertSame('/v1/order/two-1/refund', $context['endpoint']);
    }

    /**
     * @dataProvider storedMaps
     */
    public function testAStoredMapIsReadOnlyAsTheFormWritesIt($stored, ?array $expected, string $description): void
    {
        $this->assertSame($expected, TaxCodeMap::read($stored), $description);
    }

    public static function storedMaps(): array
    {
        return [
            [null, [], 'nothing stored: nothing mapped'],
            ['', [], 'blank: nothing mapped'],
            ['{"5|exempt":"ES_IVA_EXPORT"}', ['5|exempt' => 'ES_IVA_EXPORT'], 'a valid map'],
            ['not json', null, 'not JSON: unreadable'],
            ['["ES_IVA_EXPORT"]', null, 'a list: unreadable'],
            ['{"5|exempt":"ES_IVA_EXPORT","junk":"X"}', null, 'one bad row: unreadable, never part-read'],
        ];
    }

    /** @var \Two\Gateway\Service\Api\Adapter|null */
    private $twoAdapter;

    private function assertCheck(?string $refusal, callable $check, string $description): void
    {
        if ($refusal === null) {
            $check();
            $this->addToAssertionCount(1);
            return;
        }
        try {
            $check();
            $this->fail($description . ': not refused');
        } catch (ShopMatchRefusedException $e) {
            $this->assertSame($refusal, $e->getMessage(), $description);
        }
    }

    private function checks(): \Two\Gateway\Service\Order\ShopMatchChecks
    {
        return $this->shopMatchChecks($this->log());
    }

    private function config(): ConfigRepository
    {
        return $this->createMock(ConfigRepository::class);
    }

    private function log(): LogRepository
    {
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data) {
            $this->errorLog[] = [$type, $data];
        });
        $log->method('addDebugLog')->willReturnCallback(function (string $type, $data) {
            $this->debugLog[] = [$type, $data];
        });

        return $log;
    }

    private static function context(string $requestType, float $grand = 150.0, float $giftCard = 0.0): array
    {
        $order = new Order();
        foreach (['entity_id' => 7, 'store_id' => 1, 'increment_id' => '000000042', 'two_order_id' => 'two-1',
            'grand_total' => $grand, 'tax_amount' => 21.0, 'gift_cards_amount' => $giftCard,
            'two_shipping_tax_rate_source' => 'declared', 'two_shipping_tax_rate' => 0.0] as $key => $value) {
            $order->setData($key, $value);
        }

        $creditmemo = new Order\Creditmemo();
        $creditmemo->setOrder($order);

        return ['trigger' => 'test', 'endpoint' => 'test', 'order' => $order, 'intent_order' => $order, 'creditmemo' => $creditmemo, 'request_type' => $requestType];
    }

    /** One product at 21% and 29.00 of untaxed shipping: 150.00 of lines. */
    private static function payload(): array
    {
        $line = static fn (string|int $id, string $type, string $gross, string $net, string $tax, string $rate) => [
            'order_item_id' => $id, 'type' => $type, 'gross_amount' => $gross, 'net_amount' => $net,
            'tax_amount' => $tax, 'discount_amount' => '0.00', 'tax_rate' => $rate, 'unit_price' => $net, 'quantity' => 1,
        ];

        return [
            'gross_amount' => '150.00', 'net_amount' => '129.00', 'tax_amount' => '21.00', 'currency' => 'EUR',
            'merchant_order_id' => '000000042',
            'line_items' => [
                $line(11, 'PHYSICAL', '121.00', '100.00', '21.00', '0.210000'),
                $line('shipping', 'SHIPPING_FEE', '29.00', '29.00', '0.00', '0.000000'),
            ],
        ];
    }
}
