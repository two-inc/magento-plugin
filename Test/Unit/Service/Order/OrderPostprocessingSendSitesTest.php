<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Model\Two;
use Two\Gateway\Model\Webapi\OrderIntent;
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Observer\SalesOrderSaveAfter;
use Two\Gateway\Observer\SalesOrderShipmentAfter;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\RateLimiter;
use Two\Gateway\Service\UrlCookie;

/**
 * Every order request fires the postprocessing hook exactly once, with the
 * request type and trigger its path owns, and nothing is sent without it
 * (TWO-26092). The recorder stops each path at the send.
 */
class OrderPostprocessingSendSitesTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private $fired = [];

    /** @var int */
    private $sent = 0;

    /**
     * @dataProvider sendSites
     */
    public function testEachRequestFiresTheHookOnce(
        string $site,
        string $requestType,
        string $trigger,
        string $endpoint,
        string $description
    ): void {
        try {
            $this->{$site}();
        } catch (StopAtSend $stop) {
            // The recorder ends the path where the request would go out.
        }

        $this->assertCount(1, $this->fired, $description);
        [$type, $payload, $context] = $this->fired[0];
        $this->assertSame($requestType, $type, $description);
        $this->assertSame($trigger, $context['trigger'], $description);
        $this->assertSame($endpoint, $context['endpoint'], $description);
        $this->assertSame(0, $this->sent, $description . ': nothing reaches the adapter past a refusal');
    }

    public static function sendSites(): array
    {
        return [
            ['orderIntent', Hook::REQUEST_ORDER_INTENT, 'checkout', '/v1/order_intent', 'order intent, composed server-side'],
            ['authorize', Hook::REQUEST_ORDER_CREATE, 'checkout', '/v1/order', 'order create at placement'],
            ['addressUpdate', Hook::REQUEST_ORDER_UPDATE, 'admin_edit', '/v1/order/{id}', 'order update on an admin address edit'],
            ['confirm', Hook::REQUEST_ORDER_CONFIRM, 'confirmation', '/v1/order/{id}/confirm', 'order confirm'],
            ['captureInvoice', Hook::REQUEST_CAPTURE, 'invoice', '/v1/order/{id}/fulfillments', 'capture on an online invoice'],
            ['captureShipment', Hook::REQUEST_CAPTURE, 'shipment', '/v1/order/{id}/fulfillments', 'capture on a shipment'],
            ['captureStatus', Hook::REQUEST_CAPTURE, 'status_change', '/v1/order/{id}/fulfillments', 'capture on a fulfil-on-complete status'],
            ['refund', Hook::REQUEST_REFUND, 'credit_memo', '/v1/order/{id}/refund', 'refund on a credit memo'],
            ['void', Hook::REQUEST_CANCEL, 'cancel', '/v1/order/{id}/cancel', 'cancel from an admin Void'],
            ['orderCancel', Hook::REQUEST_CANCEL, 'cancel', '/v1/order/{id}/cancel', 'cancel when the Magento order is cancelled'],
            ['buyerCancel', Hook::REQUEST_CANCEL, 'buyer_cancel', '/v1/order/{id}/cancel', 'cancel when the buyer abandons Two\'s checkout'],
        ];
    }

    /**
     * The intent hands the hook the unsaved order its lines were composed
     * from, for the shop-match checks (TWO-26276).
     */
    public function testTheIntentCarriesTheOrderItsLinesWereComposedFrom(): void
    {
        $order = $this->createMock(Order::class);

        try {
            $this->orderIntent($order);
        } catch (StopAtSend $stop) {
            // Stopped at the send.
        }

        $this->assertCount(1, $this->fired);
        $this->assertSame($order, $this->fired[0][2]['intent_order'] ?? null);
    }

    private function orderIntent(?Order $order = null): void
    {
        $status = $this->createMock(ApiKeyStatus::class);
        $status->method('isDefinitiveFailure')->willReturn(false);
        $settings = $this->createMock(SettingsProvider::class);
        $settings->method('identityFrom')->willReturn(['id' => 'merchant-uuid', 'short_name' => 'acme']);
        $countries = $this->createMock(SupportedCountriesProvider::class);
        $countries->method('isAllowed')->willReturn(true);
        $session = new CheckoutSession();
        $session->setData('quote', $this->createMock(Quote::class));
        $composeIntent = $this->createMock(ComposeIntent::class);
        if ($order !== null) {
            $composeIntent->method('toOrder')->willReturn($order);
            $composeIntent->expects($this->once())->method('execute')
                ->with($this->anything(), [], $this->identicalTo($order))
                ->willReturn(['buyer' => []]);
        } else {
            $composeIntent->method('execute')->willReturn(['buyer' => []]);
        }

        (new OrderIntent(
            $this->adapter(),
            $status,
            $settings,
            $this->createMock(RateLimiter::class),
            $this->createMock(LogRepository::class),
            $session,
            $this->createMock(BuyerCountryResolver::class),
            $countries,
            $composeIntent,
            $this->recorder()
        ))->place('{"buyer":{}}');
    }

    private function authorize(): void
    {
        $surcharge = $this->createMock(SurchargeCalculator::class);
        $surcharge->method('isSurchargeResolvable')->willReturn(true);
        $countries = $this->createMock(SupportedCountriesProvider::class);
        $countries->method('isAllowed')->willReturn(true);
        $composeOrder = $this->createMock(ComposeOrder::class);
        $composeOrder->method('execute')->willReturn(['gross_amount' => '0.00']);

        $this->two([
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            'surchargeCalculator' => $surcharge,
            'buyerCountryResolver' => $this->createMock(BuyerCountryResolver::class),
            'supportedCountriesProvider' => $countries,
            'urlCookie' => $this->createMock(UrlCookie::class),
            'compositeOrder' => $composeOrder,
        ])->authorize(new SendSitePayment($this->order(), ['companyId' => '123456789']), 0.0);
    }

    private function addressUpdate(): void
    {
        $order = $this->order();
        $order->getPayment()->setData('additional_information', [
            'buyer' => [
                'company' => ['company_name' => 'Acme', 'organization_number' => '123456789'],
                'representative' => ['phone_number' => '+4712345678'],
            ],
        ]);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);
        $composeOrder = $this->createMock(ComposeOrder::class);
        $composeOrder->method('execute')->willReturn(['gross_amount' => '0.00']);

        (new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $this->createMock(BrandRegistryInterface::class),
            $orders,
            $composeOrder,
            $this->adapter(),
            $this->overlay(),
            $this->createMock(\Magento\Framework\Message\ManagerInterface::class),
            $this->recorder()
        ))->execute(new SendSiteObserver(new DataObject(['order_id' => 1])));
    }

    private function confirm(): void
    {
        $this->orderService()->confirmOrder($this->order());
    }

    private function orderCancel(): void
    {
        $this->orderService()->cancelTwoOrder($this->order());
    }

    private function buyerCancel(): void
    {
        $this->orderService()->cancelTwoOrder($this->order(), 'buyer_cancel');
    }

    private function captureInvoice(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('invoice');
        $this->two(['configRepository' => $config])->capture(new SendSitePayment($this->order()), 0.0);
    }

    private function captureShipment(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('shipment');

        (new SalesOrderShipmentAfter(
            $config,
            $this->createMock(BrandRegistryInterface::class),
            $this->adapter(),
            $this->createMock(\Magento\Sales\Model\Order\Status\HistoryFactory::class),
            $this->createMock(\Magento\Sales\Api\OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(ComposeShipment::class),
            $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
            $this->createMock(\Magento\Framework\DB\TransactionFactory::class),
            $this->overlay(),
            $this->createMock(\Two\Gateway\Service\Invoice\UploadService::class),
            $this->createMock(LogRepository::class),
            $this->createMock(\Two\Gateway\Service\Order\LifecycleEventDispatcher::class),
            $this->recorder()
        ))->execute(new SendSiteObserver(new DataObject(['shipment' => new DataObject(['order' => $this->order()])])));
    }

    private function captureStatus(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('complete');
        $config->method('getFulfillOrderStatusList')->willReturn(['complete']);

        (new SalesOrderSaveAfter(
            $config,
            $this->createMock(BrandRegistryInterface::class),
            $this->adapter(),
            $this->createMock(\Magento\Sales\Model\Order\Status\HistoryFactory::class),
            $this->createMock(\Magento\Sales\Api\OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
            $this->createMock(\Magento\Framework\DB\TransactionFactory::class),
            $this->overlay(),
            $this->recorder(),
            $this->createMock(ComposeShipment::class)
        ))->execute(new SendSiteObserver(new DataObject(['order' => $this->order()])));
    }

    private function refund(): void
    {
        $composeRefund = $this->createMock(ComposeRefund::class);
        $composeRefund->method('execute')->willReturn(['amount' => '0.00', 'line_items' => []]);
        $payment = new SendSitePayment($this->order());
        $payment->creditmemo = new \Magento\Sales\Model\Order\Creditmemo();

        $this->two(['composeRefund' => $composeRefund])->refund($payment, 0.0);
    }

    private function void(): void
    {
        $this->two([])->void(new SendSitePayment($this->order()));
    }

    private function two(array $collaborators): Two
    {
        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $collaborators += [
            'configRepository' => $this->createMock(ConfigRepository::class),
            'brandRegistry' => $this->createMock(BrandRegistryInterface::class),
            'apiAdapter' => $this->adapter(),
            'logRepository' => $this->createMock(LogRepository::class),
            'composeCapture' => $this->createMock(ComposeCapture::class),
            'orderPostprocessor' => $this->recorder(),
        ];
        foreach ($collaborators as $property => $value) {
            (new \ReflectionProperty(Two::class, $property))->setValue($two, $value);
        }

        return $two;
    }

    private function orderService(): OrderService
    {
        $service = $this->getMockBuilder(OrderService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        foreach (['apiAdapter' => $this->adapter(), 'orderPostprocessor' => $this->recorder()] as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function order(): Order
    {
        $order = new SendSiteOrder();
        $order->setData('store_id', 1);
        $order->setData('two_order_reference', 'reference');
        $order->setData('entity_id', 7);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('order_currency_code', 'EUR');
        $order->setData('status', 'complete');
        $order->setData('all_visible_items', []);
        $payment = new \Magento\Sales\Model\Order\Payment();
        $payment->setData('method', 'two_payment');
        $payment->setData('method_instance', new SendSiteMethodInstance());
        $order->setData('payment', $payment);

        return $order;
    }

    private function adapter(): Adapter
    {
        $adapter = $this->createMock(Adapter::class);
        // A GET is a read with no body, so the hook does not cover it.
        $adapter->method('execute')->willReturnCallback(function (string $endpoint, array $payload = [], string $method = 'POST'): array {
            if ($method !== 'GET') {
                $this->sent++;
            }
            return [];
        });
        $adapter->method('executeWithStatus')->willReturnCallback(function (): array {
            $this->sent++;
            return ['status' => 200, 'body' => []];
        });

        return $adapter;
    }

    private function overlay(): BrandOverlayRegistryInterface
    {
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);

        return $overlay;
    }

    private function recorder(): OrderPostprocessor
    {
        $recorder = $this->createMock(OrderPostprocessor::class);
        $recorder->method('process')->willReturnCallback(function (string $type, array $payload, array $context) {
            $this->fired[] = [$type, $payload, $context];
            throw new StopAtSend();
        });

        return $recorder;
    }
}

class SendSiteOrder extends Order implements \Magento\Sales\Api\Data\OrderInterface
{
}

/**
 * An Error rather than an Exception, so no path's own catch-all swallows it.
 */
class StopAtSend extends \Error
{
}

class SendSiteObserver extends Observer
{
    /** @var DataObject */
    private $event;

    public function __construct(DataObject $event)
    {
        $this->event = $event;
    }

    public function getEvent(): DataObject
    {
        return $this->event;
    }
}

class SendSitePayment implements InfoInterface
{
    /** @var Order */
    private $order;

    /** @var array */
    private $additionalInformation;

    /** @var mixed */
    public $creditmemo;

    public function __construct(Order $order, array $additionalInformation = [])
    {
        $this->order = $order;
        $this->additionalInformation = $additionalInformation;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getAdditionalInformation(): array
    {
        return $this->additionalInformation;
    }

    public function getCreditmemo()
    {
        return $this->creditmemo;
    }
}

class SendSiteMethodInstance
{
    /**
     * @param mixed $response
     * @return null
     */
    public function getErrorFromResponse($response)
    {
        return null;
    }
}
