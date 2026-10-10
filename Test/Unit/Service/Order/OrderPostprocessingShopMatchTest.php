<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingTotals;
use Two\Gateway\Test\Unit\Service\Order\Doubles\PostprocessorFactory;
use Two\OrderPostprocessingFixture\Plugin\Subscriber;

require_once __DIR__ . '/../../../Integration/OrderPostprocessingFixture/Plugin/Subscriber.php';

/**
 * The shop-match checks belong to the hook's default handler (TWO-26276):
 * with no other handler they refuse exactly as the builder did; a subscriber
 * takes them over, and can opt back in. The one shop-match check is the
 * shipping tax fallback reconcile (TWO-26117).
 */
class OrderPostprocessingShopMatchTest extends TestCase
{
    use PostprocessorFactory;

    private const FIXTURE = 'two_order_postprocessing_fixture';
    private const BUYER_REFUSAL = 'This order could not be placed. Please contact the merchant.';
    private const MERCHANT_REFUSAL = 'Shipping tax on this order does not match the rate of the Tax Class for Shipping (Stores > Configuration > Sales > Tax > Tax Classes), which applies because Magento recorded no shipping tax rate.';

    /** @var array<int, array{0: string, 1: mixed}> */
    private $errorLog = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private $debugLog = [];

    protected function setUp(): void
    {
        Subscriber::$mode = null;
        Subscriber::$calls = [];
    }

    /**
     * No other handler: the default handler refuses a shipping line whose
     * fallback rate the charged tax does not follow, in the builder's wording.
     *
     * @dataProvider requests
     */
    public function testWithNoSubscriberTheDefaultHandlerRefusesAsTheBuilderDid(
        string $requestType,
        string $subject,
        string $refusal,
        string $description
    ): void {
        $this->assertRefused($refusal, fn () => $this->send($requestType, $subject, false), $description);
    }

    /**
     * A subscriber owns the shop-match checks: the same line is sent, and the
     * delegation is logged once, naming the subscriber.
     *
     * @dataProvider requests
     */
    public function testASubscriberTakesTheChecksOverAndIsNamedInTheLog(
        string $requestType,
        string $subject,
        string $refusal,
        string $description
    ): void {
        $sent = $this->send($requestType, $subject, true);

        $this->assertSame($this->payload($requestType), $sent, $description);
        $this->assertSame([], $this->errorLog, $description);
        $this->assertSame(
            [['request_type' => $requestType, 'trigger' => 'test', 'handlers' => [self::FIXTURE => Subscriber::class]]],
            $this->debugEntries('OrderPostprocessingShopMatchDelegated'),
            $description
        );
    }

    /**
     * A subscriber that calls the opt-in checks gets the default handler's refusal back.
     *
     * @dataProvider requests
     */
    public function testTheOptInChecksRestoreTheRefusal(
        string $requestType,
        string $subject,
        string $refusal,
        string $description
    ): void {
        Subscriber::$mode = Subscriber::MODE_OPT_IN;

        $this->assertRefused($refusal, fn () => $this->send($requestType, $subject, true), $description);
    }

    public static function requests(): array
    {
        return [
            [Hook::REQUEST_ORDER_INTENT, 'intent_order', self::BUYER_REFUSAL, 'order intent, on the order converted from the quote'],
            [Hook::REQUEST_ORDER_CREATE, 'order', self::BUYER_REFUSAL, 'order create'],
            [Hook::REQUEST_ORDER_UPDATE, 'order', self::BUYER_REFUSAL, 'order update'],
            [Hook::REQUEST_CAPTURE, 'invoice', self::MERCHANT_REFUSAL, 'partial capture of an invoice'],
            [Hook::REQUEST_CAPTURE, 'shipment', self::MERCHANT_REFUSAL, 'partial capture of a shipment, on the order\'s shipping'],
        ];
    }

    /**
     * Each check applies to the line it was made for while that line is
     * unchanged; a line the subscriber edited or removed is its own.
     *
     * @dataProvider optInEdits
     */
    public function testTheOptInChecksSkipALineTheSubscriberChanged(
        callable $edit,
        ?string $refusal,
        string $description
    ): void {
        $log = $this->log();
        $subscriber = new class ($this->shopMatchChecks($log), $edit) {
            /** @var \Two\Gateway\Api\OrderPostprocessingShopMatchInterface */
            private $shopMatch;

            /** @var callable */
            private $edit;

            public function __construct($shopMatch, callable $edit)
            {
                $this->shopMatch = $shopMatch;
                $this->edit = $edit;
            }

            public function afterProcess(Hook $subject, array $result, array $payload, array $context): array
            {
                $result = ($this->edit)($result);
                $this->shopMatch->check($result, $payload, $context);

                return $result;
            }
        };
        $postprocessor = $this->buildPostprocessor($this->hookChain($log, ['acme_subscriber' => $subscriber]), $this->config(), $log);
        $send = fn () => $postprocessor->process(Hook::REQUEST_ORDER_CREATE, $this->payload(Hook::REQUEST_ORDER_CREATE), $this->context('order'));

        if ($refusal === null) {
            $this->assertIsArray($send(), $description);
            $this->assertSame([], $this->errorLog, $description);
        } else {
            $this->assertRefused($refusal, $send, $description);
        }
    }

    public static function optInEdits(): array
    {
        $shipping = static fn (array $payload, callable $edit): array => array_merge($payload, [
            'line_items' => array_map(
                static fn (array $line): array => $line['order_item_id'] === 'shipping' ? $edit($line) : $line,
                $payload['line_items']
            ),
        ]);

        return [
            [static fn (array $p): array => $p, self::BUYER_REFUSAL, 'untouched: refused'],
            [static fn (array $p): array => array_merge($p, ['gross_amount' => '151.00']), self::BUYER_REFUSAL, 'a total moved, the shipping line untouched: refused'],
            [static fn (array $p): array => array_merge($p, ['line_items' => array_reverse($p['line_items'])]), self::BUYER_REFUSAL, 'lines reordered: still the same line, refused'],
            [static fn (array $p): array => $shipping($p, static fn (array $l): array => array_reverse($l, true)), self::BUYER_REFUSAL, 'keys reordered: still the same line, refused'],
            [static fn (array $p): array => $shipping($p, static fn (array $l): array => array_merge($l, ['tax_amount' => '6.09', 'gross_amount' => '35.09'])), null, 'shipping tax edited: the subscriber\'s line, sent'],
            [static fn (array $p): array => array_merge($p, ['line_items' => [$p['line_items'][0]]]), null, 'shipping line removed: nothing to check, sent'],
        ];
    }

    /**
     * Unchanged where it should be: a reconciling line passes, a refund is
     * never checked, and a recorded rate is never the fallback's.
     *
     * @dataProvider unrefusedCases
     */
    public function testWhatTheBuilderNeverRefusedIsStillSent(
        string $requestType,
        string $subject,
        array $record,
        string $description
    ): void {
        $sent = $this->send($requestType, $subject, false, $record);

        $this->assertSame($this->payload($requestType), $sent, $description);
        $this->assertSame([], $this->errorLog, $description);
        $this->assertSame([], $this->debugLog, $description . ': nothing delegated with no subscriber');
    }

    public static function unrefusedCases(): array
    {
        $reconciles = ['two_shipping_tax_rate_source' => 'none', 'two_shipping_tax_rate' => 21.0, 'shipping_tax_amount' => 6.09];
        $declared = ['two_shipping_tax_rate_source' => 'declared', 'two_shipping_tax_rate' => 21.0];

        return [
            [Hook::REQUEST_ORDER_CREATE, 'order', $reconciles, 'the charged tax follows the fallback rate'],
            [Hook::REQUEST_CAPTURE, 'invoice', $reconciles, 'the same on a capture'],
            [Hook::REQUEST_ORDER_CREATE, 'order', $declared, 'Magento recorded the rate: never the fallback\'s check'],
            [Hook::REQUEST_REFUND, 'order', [], 'a refund relays its parent line\'s rate unchecked'],
        ];
    }

    /**
     * A check that cannot see the order the line was built from fails closed:
     * under the hook that is a subscriber-style failure, never a pass.
     *
     * @dataProvider missingSubjects
     */
    public function testAMissingSubjectFailsClosed(string $requestType, array $drop, string $description): void
    {
        $context = array_diff_key($this->context($requestType === Hook::REQUEST_ORDER_INTENT ? 'intent_order' : 'invoice'), array_flip($drop));

        try {
            $this->postprocessor(false)->process($requestType, $this->payload($requestType), $context);
            $this->fail($description . ': nothing was refused');
        } catch (LocalizedException $e) {
            $this->assertNotInstanceOf(ShopMatchRefusedException::class, $e, $description);
            $refused = array_values(array_filter($this->errorLog, static fn (array $entry): bool => $entry[0] === 'OrderPostprocessingRefused'));
            $this->assertSame('TWO_ORDER_POSTPROCESSING_HOOK_FAILED', $refused[0][1]['code'] ?? null, $description);
            $this->assertSame(\LogicException::class, $refused[0][1]['details']['exception'] ?? null, $description);
        }
    }

    public static function missingSubjects(): array
    {
        return [
            [Hook::REQUEST_ORDER_INTENT, ['intent_order'], 'intent without its converted order'],
            [Hook::REQUEST_ORDER_CREATE, ['order', 'invoice'], 'create without its order'],
            [Hook::REQUEST_CAPTURE, ['order', 'invoice'], 'capture without order or invoice'],
        ];
    }

    /**
     * With both kinds failing and no subscriber, the shop-match refusal still
     * comes first, as it did when the builder raised it.
     */
    public function testTheShopMatchRefusalPrecedesTheLineTaxReconcile(): void
    {
        $payload = $this->payload(Hook::REQUEST_ORDER_CREATE);
        $payload['line_items'][0]['tax_amount'] = '25.00';

        $this->assertRefused(
            self::BUYER_REFUSAL,
            fn () => $this->postprocessor(false)->process(Hook::REQUEST_ORDER_CREATE, $payload, $this->context('order')),
            'both checks fail'
        );
        $this->assertSame(['ShippingTaxFallbackMismatch'], array_column($this->errorLog, 0));
    }

    private function assertRefused(string $message, callable $send, string $description): void
    {
        try {
            $send();
            $this->fail($description . ': nothing was refused');
        } catch (LocalizedException $e) {
            $this->assertInstanceOf(ShopMatchRefusedException::class, $e, $description);
            $this->assertSame($message, $e->getMessage(), $description);
            $this->assertSame(['ShippingTaxFallbackMismatch'], array_column($this->errorLog, 0), $description . ': logged as the builder logged it, never as a hook failure');
        }
    }

    private function send(string $requestType, string $subject, bool $subscriber, array $record = []): array
    {
        return $this->postprocessor($subscriber)->process($requestType, $this->payload($requestType), $this->context($subject, $record));
    }

    private function postprocessor(bool $subscriber): OrderPostprocessor
    {
        $log = $this->log();
        $subscribers = $subscriber
            ? [self::FIXTURE => new Subscriber(new PostprocessingTotals(), $this->shopMatchChecks($log))]
            : [];

        return $this->buildPostprocessor($this->hookChain($log, $subscribers), $this->config(), $log);
    }

    private function config(): ConfigRepository
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getShippingTaxClassId')->willReturn(null);

        return $config;
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

    /**
     * @param string $type
     * @return array<int, mixed>
     */
    private function debugEntries(string $type): array
    {
        return array_values(array_map(
            static fn (array $entry) => $entry[1],
            array_filter($this->debugLog, static fn (array $entry): bool => $entry[0] === $type)
        ));
    }

    /**
     * An order placed with the shipping tax fallback at 21% and 29.00 of
     * shipping charged untaxed, the request's subject under $subject.
     *
     * @param string $subject intent_order, order, invoice or shipment
     * @param array $record overrides on the order's data
     * @return array
     */
    private function context(string $subject, array $record = []): array
    {
        $order = new Order();
        foreach ([
            'id' => 7,
            'entity_id' => 7,
            'store_id' => 1,
            'increment_id' => '000000042',
            'shipping_amount' => 29.00,
            'shipping_discount_amount' => 0.00,
            'shipping_tax_amount' => 0.00,
            'two_shipping_tax_rate_source' => 'none',
            'two_shipping_tax_rate' => 21.0,
        ] as $key => $value) {
            $order->setData($key, array_key_exists($key, $record) ? $record[$key] : $value);
        }
        $context = ['trigger' => 'test', 'endpoint' => 'test'];

        switch ($subject) {
            case 'intent_order':
                return $context + ['quote' => null, 'intent_order' => $order];
            case 'invoice':
                $invoice = new Invoice();
                $invoice->setOrder($order);
                $invoice->setData('shipping_amount', 29.00);
                $invoice->setData('shipping_tax_amount', $order->getData('shipping_tax_amount'));
                return $context + ['order' => $order, 'invoice' => $invoice];
            case 'shipment':
                return $context + ['order' => $order, 'shipment' => new \stdClass()];
            default:
                return $context + ['order' => $order];
        }
    }

    private function payload(string $requestType): array
    {
        $lines = [
            [
                'order_item_id' => 11,
                'type' => 'PHYSICAL',
                'gross_amount' => '121.00',
                'net_amount' => '100.00',
                'tax_amount' => '21.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.210000',
                'unit_price' => '100.000000',
                'quantity' => 1,
            ],
            [
                'order_item_id' => 'shipping',
                'type' => 'SHIPPING_FEE',
                'gross_amount' => '29.00',
                'net_amount' => '29.00',
                'tax_amount' => '0.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.210000',
                'unit_price' => '29.000000',
                'quantity' => 1,
            ],
        ];
        $block = ['gross_amount' => '150.00', 'net_amount' => '129.00', 'tax_amount' => '21.00', 'line_items' => $lines];

        switch ($requestType) {
            case Hook::REQUEST_CAPTURE:
                return ['partial' => $block];
            case Hook::REQUEST_REFUND:
                return ['amount' => '150.00', 'currency' => 'EUR', 'line_items' => $lines];
            default:
                return $block + ['currency' => 'EUR'];
        }
    }
}
