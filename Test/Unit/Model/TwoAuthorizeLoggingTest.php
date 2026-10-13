<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\UrlCookie;

/**
 * TWO-26340: what an order create writes to the plugin's logs. The order
 * response carries the buyer's details, so with debug logging off nothing
 * logs it; the adapter's own lines are covered in AdapterTest.
 */
class TwoAuthorizeLoggingTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string, 2: mixed}> */
    private array $logged = [];

    /** @var string[] the subjects the create was sent under */
    private array $subjects = [];

    /**
     * TWO-26259: a refused create reaches the error log once, with the API's
     * reason and the quote it was for. The adapter writes that line; the
     * create names its quote to it and logs nothing of its own.
     */
    public function testARefusedCreateIsLoggedOnceUnderItsQuote(): void
    {
        $this->authorize([
            'http_status' => 400,
            'error_code' => 'ORDER_INVALID',
            'error_message' => 'Order is invalid',
            'error_details' => 'line_items[0].tax_rate does not match',
        ]);

        $this->assertSame(['quote 42'], $this->subjects);
        $this->assertSame([], $this->logged, 'the adapter\'s line is the only one');
    }

    /**
     * Runs authorize() against a create that answers $response, recording
     * every line the log repository is given.
     */
    private function authorize(array $response): void
    {
        $log = $this->createMock(LogRepository::class);
        foreach (['addDebugLog' => 'debug', 'addErrorLog' => 'error', 'addLog' => 'log'] as $method => $level) {
            $log->method($method)->willReturnCallback(function (string $type, $data) use ($level): void {
                $this->logged[] = [$level, $type, $data];
            });
        }
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('withLogSubject')->willReturnCallback(function (string $subject) use (&$adapter) {
            $this->subjects[] = $subject;
            return $adapter;
        });
        $adapter->method('execute')->willReturn($response);
        $surcharge = $this->createMock(SurchargeCalculator::class);
        $surcharge->method('isSurchargeResolvable')->willReturn(true);
        $countries = $this->createMock(SupportedCountriesProvider::class);
        $countries->method('isAllowed')->willReturn(true);
        $composeOrder = $this->createMock(ComposeOrder::class);
        $composeOrder->method('execute')->willReturn(['gross_amount' => '0.00']);
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $collaborators = [
            'configRepository' => $this->createMock(ConfigRepository::class),
            'brandRegistry' => $brand,
            'apiAdapter' => $adapter,
            'logRepository' => $log,
            'orderPostprocessor' => $postprocessor,
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            'surchargeCalculator' => $surcharge,
            'buyerCountryResolver' => $this->createMock(BuyerCountryResolver::class),
            'supportedCountriesProvider' => $countries,
            'urlCookie' => $this->createMock(UrlCookie::class),
            'compositeOrder' => $composeOrder,
        ];
        foreach ($collaborators as $property => $value) {
            (new \ReflectionProperty(Two::class, $property))->setValue($two, $value);
        }

        $order = new Order();
        $order->setData('store_id', 1);
        $order->setData('quote_id', 42);
        $order->setData('order_currency_code', 'EUR');
        $order->setData('all_visible_items', []);
        try {
            $two->authorize(new AuthorizeLoggingPayment($order), 0.0);
        } catch (\Throwable $e) {
            // A refused or declined create throws for the buyer; the log is what is under test.
        }
    }
}

class AuthorizeLoggingPayment implements InfoInterface
{
    /** @var Order */
    private $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getAdditionalInformation(): array
    {
        return ['companyId' => '123456789'];
    }
}
