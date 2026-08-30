<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;

/**
 * TWO-25386: the admin address-edit observer re-composes the order payload
 * from the stored payment additional_information. Now that blank optional
 * fields are omitted rather than sent as '', the Department and Project keys
 * are simply absent for any buyer who left those checkout fields empty — the
 * common case. Reading them unguarded raised a PHP warning, which developer
 * mode promotes to an exception; the observer's own catch swallowed it into
 * order status history and the edit request was never sent at all.
 *
 * The tests below install an error handler that turns warnings into
 * exceptions, so an unguarded read fails here the way it fails in developer
 * mode rather than passing silently.
 */
class SalesOrderAddressUpdateOptionalFieldsTest extends TestCase
{
    /**
     * The shipping address the composer produces. Held as a constant so the
     * "unchanged" assertions compare against the exact composed value.
     */
    private const EXPECTED_SHIPPING_ADDRESS = [
        'street_address' => 'Storgata 1',
        'city' => 'Oslo',
        'postal_code' => '0155',
        'country' => 'NO',
    ];

    /** @var array captured $additionalData handed to the composer */
    private $capturedAdditionalData;

    private const ORDER_STORE_ID = 7;

    /** @var array captured [endpoint, payload, method, storeId] of the API call */
    private $capturedApiCall;

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    protected function setUp(): void
    {
        $this->capturedAdditionalData = null;
        $this->capturedApiCall = null;

        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private function makeObserverService(): SalesOrderAddressUpdate
    {
        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $composeOrder->method('execute')
            ->willReturnCallback(function ($order, $orderReference, array $additionalData): array {
                $this->capturedAdditionalData = $additionalData;
                return [
                    'order_reference' => $orderReference,
                    // shipping_address is composed on every call. It is a
                    // different field from shipping_details and must reach the
                    // request untouched — see the tests below.
                    'shipping_address' => self::EXPECTED_SHIPPING_ADDRESS,
                ];
            });

        $this->apiAdapter = $this->createMock(Adapter::class);
        $this->apiAdapter->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (
                string $endpoint,
                array $payload = [],
                string $method = 'POST',
                ?int $storeId = null
            ): array {
                $this->capturedApiCall = [$endpoint, $payload, $method, $storeId];
                return ['id' => 'remote-order-id'];
            });

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($this->order);

        $overlayRegistry = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlayRegistry->method('isTwoStackMethod')->willReturn(true);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Test Product');

        return new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $brandRegistry,
            $orderRepository,
            $composeOrder,
            $this->apiAdapter,
            $overlayRegistry
        );
    }

    /** @var AddressUpdateOrderStub */
    private $order;

    private function makeOrder(array $additionalInformation): AddressUpdateOrderStub
    {
        $order = new AddressUpdateOrderStub();
        $order->setData('store_id', self::ORDER_STORE_ID);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('two_order_reference', 'order-reference');
        $order->setData('payment', new AddressUpdatePaymentStub($additionalInformation));

        return $order;
    }

    /**
     * @return array additional_information as stored for a buyer who left
     *               both optional checkout fields empty
     */
    private function storedInformationWithoutOptionalFields(): array
    {
        return [
            'buyer' => [
                'company' => [
                    'company_name' => 'Buyer Company',
                    'organization_number' => '123456789',
                ],
                'representative' => [
                    'phone_number' => '+4712345678',
                ],
            ],
        ];
    }

    public function testMissingDepartmentAndProjectKeysStillSendTheEditRequest(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $this->makeObserverService()->execute(
            new AddressUpdateObserverStub(new AddressUpdateEventStub(42))
        );

        $this->assertNotNull(
            $this->capturedApiCall,
            'the order-edit request must still be attempted'
        );
        $this->assertSame('/v1/order/remote-order-id', $this->capturedApiCall[0]);
        $this->assertSame('PUT', $this->capturedApiCall[2]);
        // Admin/cron-initiated, so the request carries no scope — a null store
        // would resolve the default scope's API key and firewall token.
        $this->assertSame(
            self::ORDER_STORE_ID,
            $this->capturedApiCall[3],
            'the API call is scoped to the order\'s store'
        );
        $this->assertSame(
            1,
            $this->order->saveCount,
            'the order is saved once, after the edit request'
        );
    }

    public function testMissingDepartmentAndProjectKeysAreNotReportedAsAnError(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $this->makeObserverService()->execute(
            new AddressUpdateObserverStub(new AddressUpdateEventStub(42))
        );

        $this->assertSame(
            ['Order edit request was accepted by Test Product'],
            $this->order->historyComments,
            'history should record acceptance, not a swallowed warning'
        );
    }

    public function testMissingDepartmentAndProjectAreRecomposedAsBlank(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $this->makeObserverService()->execute(
            new AddressUpdateObserverStub(new AddressUpdateEventStub(42))
        );

        // Blank is the right hand-off: the composer applies the omit rule
        // again, so the outgoing payload leaves both keys out.
        $this->assertSame('', $this->capturedAdditionalData['department']);
        $this->assertSame('', $this->capturedAdditionalData['project']);
    }

    public function testStoredDepartmentAndProjectAreForwardedUnchanged(): void
    {
        $stored = $this->storedInformationWithoutOptionalFields();
        $stored['buyer_department'] = 'Facilities';
        $stored['buyer_project'] = 'Q3 Refit';
        $this->order = $this->makeOrder($stored);

        $this->makeObserverService()->execute(
            new AddressUpdateObserverStub(new AddressUpdateEventStub(42))
        );

        $this->assertSame('Facilities', $this->capturedAdditionalData['department']);
        $this->assertSame('Q3 Refit', $this->capturedAdditionalData['project']);
    }

    /*
     * TWO-25386 (scope extension): the observer used to append
     * merchant_reference, merchant_additional_info and shipping_details to
     * every edit request, so every admin address edit sent them blank —
     * shipping_details as a two-field object with nothing else populated. The
     * plugin never has a value for any of the three, so they are now left out
     * of the request under every condition. Why sending them is harmful, and
     * the edit-merge semantics behind that, are recorded on TWO-25386.
     */

    /**
     * @return array the captured request payload for the current order
     */
    private function executeAndCapturePayload(): array
    {
        $this->makeObserverService()->execute(
            new AddressUpdateObserverStub(new AddressUpdateEventStub(42))
        );

        $this->assertNotNull($this->capturedApiCall, 'the edit request must be attempted');

        return $this->capturedApiCall[1];
    }

    public function testBlankMerchantReferenceFieldsAreOmittedFromTheEditRequest(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $payload = $this->executeAndCapturePayload();

        $this->assertArrayNotHasKey(
            'merchant_reference',
            $payload,
            'the plugin never has a value for it, so it must not be sent'
        );
        $this->assertArrayNotHasKey(
            'merchant_additional_info',
            $payload,
            'the plugin never has a value for it, so it must not be sent'
        );
    }

    public function testBlankShippingDetailsIsOmittedFromTheEditRequest(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $payload = $this->executeAndCapturePayload();

        $this->assertArrayNotHasKey(
            'shipping_details',
            $payload,
            'the plugin never has a value for it, so it must not be sent'
        );
    }

    public function testShippingAddressSurvivesWhenOptionalFieldsAreOmitted(): void
    {
        $this->order = $this->makeOrder($this->storedInformationWithoutOptionalFields());

        $payload = $this->executeAndCapturePayload();

        $this->assertArrayHasKey('shipping_address', $payload);
        $this->assertSame(
            self::EXPECTED_SHIPPING_ADDRESS,
            $payload['shipping_address'],
            'shipping_address is required and is not the field being omitted'
        );
    }
}

/**
 * Test doubles. The bootstrap's catch-all autoloader produces method-less
 * Magento classes, so the collaborators the observer reaches through are
 * declared here rather than mocked.
 */
class AddressUpdateOrderStub extends \Magento\Sales\Model\Order
{
    /** @var array */
    public $historyComments = [];

    /** @var int */
    public $saveCount = 0;

    public function getStatus(): string
    {
        return 'processing';
    }

    public function addStatusToHistory($status, $comment = ''): self
    {
        $this->historyComments[] = $comment;
        return $this;
    }

    public function save(): self
    {
        $this->saveCount++;
        return $this;
    }
}

class AddressUpdatePaymentStub
{
    /** @var array */
    private $additionalInformation;

    public function __construct(array $additionalInformation)
    {
        $this->additionalInformation = $additionalInformation;
    }

    public function getMethod(): string
    {
        return 'two_payment';
    }

    public function getAdditionalInformation(): array
    {
        return $this->additionalInformation;
    }

    public function getMethodInstance(): AddressUpdateMethodInstanceStub
    {
        return new AddressUpdateMethodInstanceStub();
    }
}

class AddressUpdateMethodInstanceStub
{
    /**
     * @param array $response
     * @return string|null
     */
    public function getErrorFromResponse($response)
    {
        return null;
    }
}

class AddressUpdateEventStub
{
    /** @var int */
    private $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }
}

class AddressUpdateObserverStub extends \Magento\Framework\Event\Observer
{
    /** @var AddressUpdateEventStub */
    private $event;

    public function __construct(AddressUpdateEventStub $event)
    {
        $this->event = $event;
    }

    public function getEvent(): AddressUpdateEventStub
    {
        return $this->event;
    }
}
