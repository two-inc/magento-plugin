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
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Test\E2E\Http\RealAdapterFactoryTrait;
use Two\Gateway\Test\E2E\Http\StagingEnvTrait;
use Two\Gateway\Test\Unit\Service\Order\Doubles\TenderOrderFixtures;

/**
 * The bodies the builders compose for an order part paid by a tender
 * (TWO-26309), sent as composed to Two's staging API: Two accepts the
 * create, and its order records the tender as prepaid and the rest as
 * payable. No shop is involved, so nothing is installed anywhere.
 *
 * Runs with `make test-e2e` and TWO_API_KEY set to a staging test merchant's
 * key and TWO_E2E_BUYER to a staging test buyer for that merchant, as JSON
 * with `buyer`, `billing_address`, `shipping_address` and `currency`; skipped
 * without either, or failed where TWO_E2E_REQUIRED is set, as CI sets it.
 *
 * The refund is not exercised: Two refunds only a fulfilled order, which
 * needs the buyer to complete Two's checkout first.
 */
class PrepaidOrderTest extends TestCase
{
    use RealAdapterFactoryTrait;
    use StagingEnvTrait;
    use TenderOrderFixtures;

    private Adapter $adapter;

    protected function setUp(): void
    {
        $apiKey = $this->stagingApiKey();
        $this->stagingBuyer();
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn($this->stagingBaseUrl());
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn($apiKey);
        $this->adapter = $this->buildRealAdapter($config, $this->createMock(LogRepository::class));
    }

    /**
     * @dataProvider tenders
     */
    public function testTwoRecordsTheTenderAsPrepaid(array $tenders, string $prepaid, string $payable, string $description): void
    {
        $body = array_merge($this->create($this->order($tenders)), $this->stagingBuyer(), [
            'merchant_order_id' => 'two-e2e-prepaid-' . bin2hex(random_bytes(4)),
        ]);

        [$status, $created] = $this->sendToStaging($this->adapter, '/v1/order', $body, 'POST');
        $this->assertSame(201, $status, $description . ': ' . json_encode($created));
        $this->assertNotEmpty($created['id'] ?? null, $description . ': an order id');

        try {
            [, $order] = $this->sendToStaging($this->adapter, '/v1/order/' . $created['id'], [], 'GET');
            $this->assertSame(
                [$prepaid, $payable],
                [$order['prepaid_amount'] ?? null, $order['payable_amount'] ?? null],
                $description . ': ' . json_encode($order)
            );
        } finally {
            $this->sendToStaging($this->adapter, '/v1/order/' . $created['id'] . '/cancel', [], 'POST');
        }
    }

    public static function tenders(): array
    {
        return [
            [['gift_cards_amount' => 10.00], '10.00', '140.00', 'a gift card'],
            [['customer_balance_amount' => 25.00], '25.00', '125.00', 'store credit'],
        ];
    }
}
