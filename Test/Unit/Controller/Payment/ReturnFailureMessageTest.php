<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Controller\Payment\Cancel;
use Two\Gateway\Controller\Payment\Confirm;
use Two\Gateway\Controller\Payment\Verificationfailed;
use Two\Gateway\Exception\TwoRefusalException;
use Two\Gateway\Service\Payment\OrderService;

/**
 * TWO-26295: a buyer's failed return to the shop shows a refusal's buyer
 * message, this module's own sentence, or the general message, never an
 * exception's internal text; the merchant keeps the full account.
 */
class ReturnFailureMessageTest extends TestCase
{
    private const GENERAL = 'Something went wrong with your request to Two. Please try again later.';

    /** @var string[] */
    private $shown = [];

    /** @var string[] */
    private $comments = [];

    /** @var array */
    private $logs = [];

    public static function cases(): array
    {
        $refusal = static fn (): \Exception => new TwoRefusalException(
            __('Two refused: raw api account'),
            __('Phone Number is not valid.')
        );
        $plain = static fn (): \Exception => new \Exception('internal detail at line 42');
        $database = static fn (): \Exception => new \PDOException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found on table sales_order'
        );
        $own = static fn (): \Exception => new LocalizedException(__('Our own sentence.'));

        $rows = [];
        foreach ([Confirm::class, Cancel::class, Verificationfailed::class] as $controller) {
            $name = substr($controller, strrpos($controller, '\\') + 1);
            $rows += [
                "$name: a refusal shows its buyer message" => [$controller, $refusal, false, 'Phone Number is not valid.'],
                "$name: a plain exception shows the general message" => [$controller, $plain, false, self::GENERAL],
                "$name: a database error shows the general message" => [$controller, $database, false, self::GENERAL],
                "$name: our own sentence is shown as it is" => [$controller, $own, false, 'Our own sentence.'],
                "$name: a failing cart restore still shows the buyer message" => [$controller, $refusal, true, 'Phone Number is not valid.'],
            ];
        }
        return $rows;
    }

    #[DataProvider('cases')]
    public function testTheBuyerSeesOnlyAMessageMeantForThem(
        string $controllerClass,
        callable $makeException,
        bool $restoreFails,
        string $expected
    ): void {
        $exception = $makeException();
        $order = new Order();
        $service = $this->orderService($controllerClass, $exception, $order, $restoreFails);

        $this->controller($controllerClass, $service)->execute();

        $description = $this->dataName();
        $this->assertSame([$expected], $this->shown, $description);
        foreach (['raw', 'internal', 'SQLSTATE', 'sales_order'] as $leak) {
            $this->assertStringNotContainsString($leak, $this->shown[0], "$description: no $leak");
        }
        // The merchant keeps the full account wherever the order was found.
        $expectedComments = $controllerClass === Verificationfailed::class ? [] : [$exception->getMessage()];
        $this->assertSame($expectedComments, $this->comments, "$description: order comment");
    }

    private function orderService(string $controllerClass, \Exception $exception, Order $order, bool $restoreFails): OrderService
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByReference', 'getTwoOrderFromApi', 'cancelTwoOrder', 'restoreQuote', 'failOrder'])
            ->getMock();
        if ($controllerClass === Verificationfailed::class) {
            $service->method('getOrderByReference')->willThrowException($exception);
        } else {
            $service->method('getOrderByReference')->willReturn($order);
        }
        $service->method('getTwoOrderFromApi')->willThrowException($exception);
        $service->method('cancelTwoOrder')->willThrowException($exception);
        if ($restoreFails) {
            $service->method('restoreQuote')->willThrowException(new \PDOException('SQLSTATE[HY000]: quote table'));
        }
        $service->method('failOrder')->willReturnCallback(function ($order, $reason) use ($service) {
            $this->comments[] = (string)$reason;
            return $service;
        });

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function ($type, $data) {
            $this->logs[] = [$type, $data];
        });
        foreach (['brandRegistry' => $brand, 'logRepository' => $log] as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function controller(string $class, OrderService $service): object
    {
        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->shown[] = (string)$message;
            return $messages;
        });
        $url = new class {
            public function getUrl($path = null, $params = null)
            {
                return 'https://shop.test/' . $path;
            }
        };
        $response = new class {
            public function setRedirect($url)
            {
                return $this;
            }
        };

        $values = [
            'orderService' => $service,
            'brandRegistry' => $brand,
            'messageManager' => $messages,
            '_url' => $url,
            '_response' => $response,
        ];
        foreach ($values as $property => $value) {
            $owner = $reflection;
            while (!$owner->hasProperty($property) && $owner->getParentClass()) {
                $owner = $owner->getParentClass();
            }
            if ($owner->hasProperty($property)) {
                $owner->getProperty($property)->setValue($controller, $value);
            }
        }

        return $controller;
    }
}
