<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Order\ComposeOrder;

/**
 * TWO-25386: optional payload fields must be omitted rather than composed as
 * empty strings, for two separate reasons.
 *
 * vendor_name has to be non-empty whenever the key is present, so a blank admin
 * setting sent as '' had the order rejected and nothing created at all. The
 * remaining fields do accept an empty string on create, but the later
 * order-edit call merges scalars — an absent key keeps the stored value while
 * an empty one overwrites it — so composing them blank was erasing what the
 * buyer entered at checkout as soon as an admin edited the order address.
 *
 * The getters are allowed to return '' (see
 * Model\Config\RepositoryAdminControlsTest) — it is the caller's job to leave
 * the key out, which is what this covers.
 *
 * Builds ComposeOrder via getMockBuilder() with the constructor disabled and
 * only the heavy collaborating helpers replaced, so execute() itself runs its
 * real implementation — same pattern as ComposeCaptureSurchargeLineTest.
 */
class ComposeOrderOptionalFieldsTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /**
     * @param string $vendorSiteName value the admin config resolves to
     * @return ComposeOrder|\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeComposeOrder(string $vendorSiteName)
    {
        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getLineItemsOrder',
                'getAddress',
                'getBuyer',
                'getTaxSubtotals',
                'getDiscountAmountItem',
                'getFeeLines',
                'getOtherChargesLineItem',
            ])
            ->getMock();

        $composeOrder->method('getLineItemsOrder')->willReturn([]);
        $composeOrder->method('getAddress')->willReturn([]);
        $composeOrder->method('getBuyer')->willReturn([]);
        $composeOrder->method('getTaxSubtotals')->willReturn([]);
        $composeOrder->method('getDiscountAmountItem')->willReturn(0.0);
        // Line-item reconciliation has its own coverage; stubbed out so this
        // test is about which keys reach the payload, nothing else.
        $composeOrder->method('getFeeLines')->willReturn([]);
        $composeOrder->method('getOtherChargesLineItem')->willReturn(null);

        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getVendorSiteName')->willReturn($vendorSiteName);
        $this->configRepository->method('getAllBuyerTerms')->willReturn([30]);
        $this->configRepository->method('getDefaultPaymentTerm')->willReturn(30);
        $this->configRepository->method('getPaymentTermsType')->willReturn('invoice_date');

        // configRepository and url are public on the parent service, so the
        // skipped constructor can be compensated for directly.
        $composeOrder->configRepository = $this->configRepository;
        $composeOrder->url = $this->createMock(Url::class);
        $composeOrder->url->method('getUrl')->willReturn('https://example.test/two');

        $property = new \ReflectionProperty(ComposeOrder::class, 'checkoutSession');
        $property->setAccessible(true);
        $property->setValue($composeOrder, new \Magento\Checkout\Model\Session());

        return $composeOrder;
    }

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->setStoreId(1);
        $order->setGrandTotal(100.00);
        $order->setTaxAmount(20.00);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');

        return $order;
    }

    public function testBlankVendorSiteNameOmitsTheKeyEntirely(): void
    {
        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', []);

        $this->assertArrayNotHasKey('vendor_name', $payload);
    }

    public function testConfiguredVendorSiteNameIsSent(): void
    {
        $payload = $this->makeComposeOrder('acme-eu-store')->execute($this->makeOrder(), 'ref', []);

        $this->assertSame('acme-eu-store', $payload['vendor_name']);
    }

    public function testUntouchedCheckoutFieldsAreOmitted(): void
    {
        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', []);

        $this->assertArrayNotHasKey('buyer_department', $payload);
        $this->assertArrayNotHasKey('buyer_project', $payload);
        $this->assertArrayNotHasKey('buyer_purchase_order_number', $payload);
        $this->assertArrayNotHasKey('order_note', $payload);
    }

    public function testBlankStringCheckoutFieldsAreOmitted(): void
    {
        $additionalData = [
            'department' => '',
            'project' => '',
            'poNumber' => '',
            'orderNote' => '',
        ];

        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', $additionalData);

        $this->assertArrayNotHasKey('buyer_department', $payload);
        $this->assertArrayNotHasKey('buyer_project', $payload);
        $this->assertArrayNotHasKey('buyer_purchase_order_number', $payload);
        $this->assertArrayNotHasKey('order_note', $payload);
    }

    public function testPopulatedCheckoutFieldsAreSent(): void
    {
        $additionalData = [
            'department' => 'Facilities',
            'project' => 'Fit-out',
            'poNumber' => 'PO-4471',
            'orderNote' => 'Deliver to the loading bay',
        ];

        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', $additionalData);

        $this->assertSame('Facilities', $payload['buyer_department']);
        $this->assertSame('Fit-out', $payload['buyer_project']);
        $this->assertSame('PO-4471', $payload['buyer_purchase_order_number']);
        $this->assertSame('Deliver to the loading bay', $payload['order_note']);
    }

    /**
     * A '0' reference is a real value, not an absent one — so the guard must
     * be a string comparison rather than empty().
     */
    public function testZeroStringCheckoutFieldIsStillSent(): void
    {
        $payload = $this->makeComposeOrder('')
            ->execute($this->makeOrder(), 'ref', ['department' => '0']);

        $this->assertSame('0', $payload['buyer_department']);
    }

    /**
     * The counterpart guard: omission must never reach the required keys.
     * A blanket empty-strip over the payload would take
     * merchant_confirmation_url with it and produce a fresh rejection.
     */
    public function testRequiredMerchantConfirmationUrlIsAlwaysPresent(): void
    {
        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', []);

        $this->assertArrayHasKey('merchant_urls', $payload);
        $this->assertNotNull($payload['merchant_urls']['merchant_confirmation_url']);
        $this->assertSame(
            'https://example.test/two',
            $payload['merchant_urls']['merchant_confirmation_url']
        );
        // Never composed as a blank string; the plugin has no such route.
        $this->assertArrayNotHasKey('merchant_edit_order_url', $payload['merchant_urls']);
    }

    public function testInvoiceDetailsOmitsBlankPaymentReferenceFields(): void
    {
        $payload = $this->makeComposeOrder('')
            ->execute($this->makeOrder(), 'ref', ['invoiceEmails' => 'ap@example.test']);

        $this->assertSame(['ap@example.test'], $payload['invoice_details']['invoice_emails']);
        $this->assertArrayNotHasKey('payment_reference_message', $payload['invoice_details']);
        $this->assertArrayNotHasKey('payment_reference_ocr', $payload['invoice_details']);
    }

    public function testInvoiceDetailsAbsentWithoutInvoiceEmails(): void
    {
        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', []);

        $this->assertArrayNotHasKey('invoice_details', $payload);
    }

    /**
     * A non-scalar cannot be a value for any of the optional fields, and the
     * observer that stores checkout data only validates that the top level is
     * an array — so a crafted request can put an array here. Casting it to
     * string raises a warning that developer mode turns into a failed order
     * placement, so the field must simply be dropped.
     */
    public function testNonScalarCheckoutFieldIsDroppedWithoutWarning(): void
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE
        );

        try {
            $payload = $this->makeComposeOrder('')
                ->execute($this->makeOrder(), 'ref', ['department' => ['x']]);
        } finally {
            restore_error_handler();
        }

        $this->assertArrayNotHasKey('buyer_department', $payload);
    }

    /**
     * Payload paths that are legitimately allowed to hold an empty string, each
     * with the reason it is sanctioned. Adding an entry has to be a deliberate
     * act — that is the point of the list.
     *
     * Numeric array indices are normalised to '*' so a path stays stable
     * regardless of how many line items a payload happens to carry.
     *
     * Empty as of TWO-25386: nothing the composer emits is currently allowed to
     * be an empty string.
     */
    private const ACCEPTED_EMPTY_PATHS = [];

    /**
     * The general form of the bug this ticket is about: walk every scalar in a
     * fully composed create payload and fail on any empty string that is not on
     * the sanctioned list above.
     *
     * This is worth more than the per-field assertions, because it needs no
     * change when a new field is added to the payload — only when a field is
     * genuinely permitted to be blank, which is exactly the decision that
     * deserves a second look.
     *
     * Scope note: address, buyer and line-item composition are stubbed out by
     * this test's fixture (they have their own coverage), so this walks the
     * payload the composer assembles itself, not those nested structures.
     */
    public function testComposedPayloadHasNoUnsanctionedEmptyStrings(): void
    {
        // Every optional input left blank: the worst case for this check, and
        // the common one in production.
        $additionalData = [
            'companyName' => '',
            'companyId' => '',
            'department' => '',
            'project' => '',
            'poNumber' => '',
            'orderNote' => '',
            'invoiceEmails' => '',
        ];

        $payload = $this->makeComposeOrder('')->execute($this->makeOrder(), 'ref', $additionalData);

        $offenders = [];
        $this->collectEmptyStringPaths($payload, '', $offenders);

        $this->assertSame(
            [],
            $offenders,
            'empty strings must be omitted, not composed into the payload'
        );
    }

    /**
     * @param array $node payload fragment being walked
     * @param string $prefix dotted path of $node within the payload
     * @param array $offenders collected paths, by reference
     */
    private function collectEmptyStringPaths(array $node, string $prefix, array &$offenders): void
    {
        foreach ($node as $key => $value) {
            $segment = is_int($key) ? '*' : (string)$key;
            $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

            if (is_array($value)) {
                $this->collectEmptyStringPaths($value, $path, $offenders);
                continue;
            }

            if ($value === '' && !array_key_exists($path, self::ACCEPTED_EMPTY_PATHS)) {
                $offenders[] = $path;
            }
        }
    }
}
