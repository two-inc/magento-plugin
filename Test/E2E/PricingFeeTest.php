<?php
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Pricing;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Test\E2E\Http\RealCurl;

/**
 * End-to-end tests for the pricing fee endpoint used by SurchargeCalculator.
 * Validates that POST /pricing/v1/portal/order/fee returns the expected
 * fee structure for various term configurations.
 *
 * Run via: TWO_API_KEY=xxx make test-e2e
 */
class PricingFeeTest extends TestCase
{
    private Adapter $adapter;

    protected function setUp(): void
    {
        $apiKey = (string)getenv('TWO_API_KEY');
        $baseUrl = getenv('TWO_API_BASE_URL') ?: 'https://api.staging.two.inc';

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn($baseUrl);
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn($apiKey);

        $log = $this->createMock(LogRepository::class);

        $this->adapter = new Adapter($config, new RealCurl(), $log);
    }

    private function fetchFee(int $durationDays, float $grossAmount = 1000.0, string $country = 'NO'): array
    {
        $result = $this->adapter->execute('/pricing/v1/portal/order/fee', [
            'buyer_country_code' => $country,
            'approved_on_recourse' => false,
            'gross_amount' => $grossAmount,
            'order_terms' => [
                'type' => 'NET_TERMS',
                'duration_days' => $durationDays,
            ],
        ]);

        // The pricing endpoint may require elevated permissions not available
        // with a standard test API key. Skip rather than fail.
        if (($result['http_status'] ?? 0) === 401) {
            $this->markTestSkipped('API key does not have access to the pricing endpoint');
        }

        return $result;
    }

    // ── Valid fee responses ───────────────────────────────────────────

    public function testFeeEndpointReturnsValidFeeFor30Days(): void
    {
        $result = $this->fetchFee(30);

        $this->assertArrayHasKey('total_fee', $result);
        $this->assertArrayHasKey('fixed_fee', $result);
        $this->assertArrayHasKey('percentage_fee', $result);
        $this->assertArrayHasKey('total_fee_tax_rate', $result);
        $this->assertGreaterThan(0, (float)$result['total_fee']);
    }

    public function testFeeEndpointReturnsValidFeeFor90Days(): void
    {
        $result = $this->fetchFee(90);

        $this->assertArrayHasKey('total_fee', $result);
        $this->assertGreaterThan(0, (float)$result['total_fee']);
    }

    public function testLongerTermsHaveHigherOrEqualFees(): void
    {
        $fee30 = $this->fetchFee(30);
        $fee90 = $this->fetchFee(90);

        $this->assertGreaterThanOrEqual(
            (float)$fee30['total_fee'],
            (float)$fee90['total_fee'],
            '90-day fee should be >= 30-day fee'
        );
    }

    // ── End of month terms ───────────────────────────────────────────

    public function testEndOfMonthTermsAccepted(): void
    {
        $result = $this->adapter->execute('/pricing/v1/portal/order/fee', [
            'buyer_country_code' => 'NO',
            'approved_on_recourse' => false,
            'gross_amount' => 1000.0,
            'order_terms' => [
                'type' => 'NET_TERMS',
                'duration_days' => 60,
                'duration_days_calculated_from' => 'END_OF_MONTH',
            ],
        ]);

        if (($result['http_status'] ?? 0) === 401) {
            $this->markTestSkipped('API key does not have access to the pricing endpoint');
        }

        $this->assertArrayHasKey('total_fee', $result);
        $this->assertArrayNotHasKey('error_code', $result);
    }

    // ── Error handling ───────────────────────────────────────────────

    public function testInvalidCountryCodeReturnsStructuredError(): void
    {
        $result = $this->fetchFee(30, 1000.0, 'XX');

        $this->assertArrayHasKey('http_status', $result);
        $this->assertGreaterThanOrEqual(400, $result['http_status']);
    }

    public function testZeroDurationDaysReturnsError(): void
    {
        $result = $this->fetchFee(0);

        $this->assertArrayHasKey('http_status', $result);
        $this->assertGreaterThanOrEqual(400, $result['http_status']);
    }
}
