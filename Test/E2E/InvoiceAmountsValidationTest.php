<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\E2E;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Test\E2E\Http\RealAdapterFactoryTrait;
use Two\Gateway\Test\E2E\Http\StagingEnvTrait;
use Two\Gateway\Test\Unit\Service\Order\Doubles\TenderOrderFixtures;

/**
 * A canary on Two's staging API (TWO-26325): an order whose lines do not add
 * up to its totals is refused. The plugin leaves that arithmetic to the API
 * (TWO-26284), so if staging ever stops refusing it, this says so.
 *
 * Runs with `make test-e2e`, TWO_API_KEY and TWO_E2E_BUYER, as PrepaidOrderTest.
 */
class InvoiceAmountsValidationTest extends TestCase
{
    use RealAdapterFactoryTrait;
    use StagingEnvTrait;
    use TenderOrderFixtures;

    public function testAnOrderWhoseLinesDoNotAddUpIsRefused(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn($this->stagingBaseUrl());
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn($this->stagingApiKey());
        $adapter = $this->buildRealAdapter($config, $this->createMock(LogRepository::class));

        // The lines are 129.00 net and 21.00 tax; each total is far outside any rounding.
        $body = array_merge($this->create($this->order([])), $this->stagingBuyer(), [
            'merchant_order_id' => 'two-e2e-amounts-' . bin2hex(random_bytes(4)),
            'net_amount' => '229.00',
            'tax_amount' => '41.00',
            'gross_amount' => '270.00',
        ]);

        [$status, $answer] = $this->sendToStaging($adapter, '/v1/order', $body, 'POST');

        try {
            $this->assertSame(
                [400, 'INVALID_ORDER_AMOUNT_DATA'],
                [$status, $answer['error_code'] ?? null],
                sprintf(
                    'Expected staging to refuse an order whose lines do not add up to its totals with 400'
                    . ' INVALID_ORDER_AMOUNT_DATA, and it answered %d. If it accepted the order, its order amount'
                    . ' validation may have been switched off (TWO-26325). Response: %s',
                    $status,
                    json_encode($answer)
                )
            );
        } finally {
            if ($status >= 200 && $status < 300 && !empty($answer['id'])) {
                $this->sendToStaging($adapter, '/v1/order/' . $answer['id'] . '/cancel', [], 'POST');
            }
        }
    }
}
