<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;

/**
 * TWO-26259: a refused order create reaches the merchant's error log with
 * the error details the buyer-facing message leaves out.
 */
class TwoOrderCreateRefusalLogTest extends TestCase
{
    /**
     * @return array<string, array{0: array, 1: array}> [response, expected log data]
     */
    public static function refusalCases(): array
    {
        return [
            'ORDER_INVALID keeps its details' => [
                [
                    'http_status' => 400,
                    'error_code' => 'ORDER_INVALID',
                    'error_message' => 'Order is invalid',
                    'error_details' => 'line_items[0].tax_rate does not match',
                ],
                [
                    'quote_id' => 42,
                    'error_code' => 'ORDER_INVALID',
                    'error_message' => 'Order is invalid',
                    'error_details' => 'line_items[0].tax_rate does not match',
                ],
            ],
            'SCHEMA_ERROR keeps its details' => [
                [
                    'http_status' => 400,
                    'error_code' => 'SCHEMA_ERROR',
                    'error_message' => 'Invalid payload',
                    'error_details' => 'buyer.company.organization_number: field required',
                ],
                [
                    'quote_id' => 42,
                    'error_code' => 'SCHEMA_ERROR',
                    'error_message' => 'Invalid payload',
                    'error_details' => 'buyer.company.organization_number: field required',
                ],
            ],
            'SAME_BUYER_SELLER_ERROR keeps the original message' => [
                [
                    'http_status' => 400,
                    'error_code' => 'SAME_BUYER_SELLER_ERROR',
                    'error_message' => 'Buyer and seller are the same',
                    'error_details' => 'organization_number matches the merchant',
                ],
                [
                    'quote_id' => 42,
                    'error_code' => 'SAME_BUYER_SELLER_ERROR',
                    'error_message' => 'Buyer and seller are the same',
                    'error_details' => 'organization_number matches the merchant',
                ],
            ],
            'a refusal with no error fields still logs, as nulls' => [
                ['http_status' => 502],
                [
                    'quote_id' => 42,
                    'error_code' => null,
                    'error_message' => null,
                    'error_details' => null,
                ],
            ],
        ];
    }

    /**
     * @dataProvider refusalCases
     */
    public function testRefusalIsLoggedForTheMerchant(array $response, array $expected): void
    {
        $logged = [];
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(
            function (string $type, $data) use (&$logged): void {
                $logged[] = [$type, $data];
            }
        );
        $log->expects($this->never())->method('addDebugLog');

        $model = $this->getMockBuilder(Two::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $prop = new \ReflectionProperty(Two::class, 'logRepository');
        $prop->setValue($model, $log);

        $order = new Order();
        $order->setData('quote_id', 42);

        $method = new \ReflectionMethod(Two::class, 'logOrderCreateRefusal');
        $method->invoke($model, $order, $response);

        $this->assertSame([['Order create refused', $expected]], $logged);
    }

    /**
     * authorize() needs the full framework to run, so pin the wiring from its
     * source, as the company-number guard's test does: the refusal is logged
     * with the create response, and before the buyer-facing exception is
     * thrown, so a refusal can never skip the log. The buyer's message is
     * asked for in order create mode, so it is the generic notice.
     */
    public function testAuthorizeLogsTheRefusalBeforeThrowing(): void
    {
        $method = new \ReflectionMethod(Two::class, 'authorize');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertMatchesRegularExpression(
            '/\$error = \$this->getErrorFromResponse\(\$response, true\);\s*'
            . 'if \(\$error\) \{\s*'
            . '\$this->logOrderCreateRefusal\(\$order, \$response\);\s*'
            . 'throw new LocalizedException\(\$error\);/',
            $source,
            'authorize() must ask for the order create message, log the refusal, then throw.'
        );
    }
}
