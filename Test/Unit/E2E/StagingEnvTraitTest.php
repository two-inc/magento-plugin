<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\E2E;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Test\E2E\Http\StagingEnvTrait;

/**
 * The staging API tests read the HTTP status of each call (TWO-26325): an
 * accepted order's own `status` field must never be taken for it.
 */
class StagingEnvTraitTest extends TestCase
{
    use StagingEnvTrait;

    /**
     * @dataProvider responses
     */
    public function testSendKeepsTheHttpStatus(array $response, array $expected, string $description): void
    {
        // Only the transport is faked: execute() runs as shipped, on top of it.
        $adapter = $this->createPartialMock(Adapter::class, ['executeWithStatus']);
        $adapter->method('executeWithStatus')->willReturn($response);

        $this->assertSame($expected, $this->sendToStaging($adapter, '/v1/order', ['a' => 1], 'POST'), $description);
    }

    public static function responses(): array
    {
        $created = ['id' => 'o1', 'status' => 'APPROVED'];
        $refused = ['error_code' => 'INVALID_ORDER_AMOUNT_DATA', 'http_status' => 400];

        return [
            [['status' => 201, 'body' => $created], [201, $created], 'an accepted order, whose body carries its own status'],
            [['status' => 400, 'body' => $refused], [400, $refused], 'a refusal'],
            [['status' => 0, 'body' => ['error_code' => 400]], [0, ['error_code' => 400]], 'no HTTP exchange'],
        ];
    }
}
