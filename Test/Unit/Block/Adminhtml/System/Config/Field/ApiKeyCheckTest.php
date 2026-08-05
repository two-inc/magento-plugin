<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\ApiKeyCheck;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * The admin API-key panel's status message.
 *
 * The regression this pins: every non-200 verification outcome used to
 * render the same "API key is not valid", so an admin whose store could
 * not reach the API was told their key was wrong and went looking for the
 * wrong fix. Each failure category must produce distinguishable wording,
 * and none of them may render the upstream response body.
 */
class ApiKeyCheckTest extends TestCase
{
    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    protected function setUp(): void
    {
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
    }

    private function build(string $apiKey = 'test-api-key'): ApiKeyCheck
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        return new ApiKeyCheck(
            $configRepository,
            $this->apiKeyStatus,
            $brandRegistry,
            $this->createMock(Context::class)
        );
    }

    private function messageFor(string $status, ?int $code, ?array $merchant = null): string
    {
        $this->apiKeyStatus->method('refresh')->willReturn(
            ['status' => $status, 'code' => $code, 'merchant' => $merchant]
        );

        return (string)$this->build()->getApiKeyStatus()['message'];
    }

    // ── The three categories that used to be indistinguishable ──────────

    public function testRejectedKeyBlamesTheKey(): void
    {
        $message = $this->messageFor(ApiKeyStatus::INVALID_KEY, 401);

        $this->assertStringContainsString('rejected', $message);
        $this->assertStringContainsString('Acme Pay', $message);
        // A rejected key is the one case where "replace the key" is the right
        // advice, so it must not hedge towards a connection problem.
        $this->assertStringNotContainsString('reached', $message);
    }

    public function testServiceErrorExoneratesTheKeyAndNamesTheStatus(): void
    {
        $message = $this->messageFor(ApiKeyStatus::SERVICE_ERROR, 503);

        $this->assertStringContainsString('503', $message);
        // The admin must be told the key may well be fine — this is the exact
        // misdiagnosis the change exists to stop.
        $this->assertStringContainsString('may be fine', $message);
    }

    public function testUnreachableIsReportedAsAConnectionProblem(): void
    {
        $message = $this->messageFor(ApiKeyStatus::UNREACHABLE, null);

        $this->assertStringContainsString('could not be reached', $message);
        $this->assertStringContainsString('connection', $message);
        // No HTTP exchange completed, so there is no status code to quote and
        // the message must not imply the key was rejected.
        $this->assertStringNotContainsString('rejected', $message);
    }

    public function testTheThreeFailureCategoriesAllReadDifferently(): void
    {
        // One assertion for the actual regression: three distinct outcomes
        // must not collapse onto one message.
        $messages = [];
        foreach ([
            [ApiKeyStatus::INVALID_KEY, 401],
            [ApiKeyStatus::SERVICE_ERROR, 500],
            [ApiKeyStatus::UNREACHABLE, null],
        ] as [$status, $code]) {
            $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
            $apiKeyStatus->method('refresh')->willReturn(
                ['status' => $status, 'code' => $code, 'merchant' => null]
            );
            $this->apiKeyStatus = $apiKeyStatus;
            $messages[] = (string)$this->build()->getApiKeyStatus()['message'];
        }

        $this->assertCount(3, array_unique($messages));
    }

    // ── Remaining categories ────────────────────────────────────────────

    public function testMalformedResponseIsItsOwnMessage(): void
    {
        $message = $this->messageFor(ApiKeyStatus::MALFORMED_RESPONSE, null);

        $this->assertStringContainsString('unexpected response', $message);
    }

    public function testOtherNonSuccessStatusQuotesTheCode(): void
    {
        $message = $this->messageFor(ApiKeyStatus::ERROR, 418);

        $this->assertStringContainsString('418', $message);
    }

    public function testMissingKeyIsAWarningNotAnError(): void
    {
        // No key stored is not a failure to report against the service, and
        // must not trigger a verification call at all.
        $this->apiKeyStatus->expects($this->never())->method('refresh');

        $result = $this->build('')->getApiKeyStatus();

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('missing', (string)$result['message']);
    }

    // ── Success ─────────────────────────────────────────────────────────

    public function testSuccessSurfacesTheResolvedMerchant(): void
    {
        $this->apiKeyStatus->method('refresh')->willReturn([
            'status' => ApiKeyStatus::OK,
            'code' => 200,
            'merchant' => ['id' => 'abc-123', 'short_name' => 'acme'],
        ]);

        $result = $this->build()->getApiKeyStatus();

        $this->assertSame('success', $result['status']);
        $this->assertSame('abc-123', $result['merchant_id']);
        $this->assertSame('acme', $result['merchant_short_name']);
    }

    /**
     * @dataProvider failureCategories
     */
    public function testEveryFailureIsFlaggedAsAnErrorAndCarriesNoMerchant(string $status, ?int $code): void
    {
        $this->apiKeyStatus->method('refresh')->willReturn(
            ['status' => $status, 'code' => $code, 'merchant' => null]
        );

        $result = $this->build()->getApiKeyStatus();

        $this->assertSame('error', $result['status']);
        // A stale merchant id must not be shown next to a failure — that is
        // how a broken key used to hide behind a cached-looking success.
        $this->assertArrayNotHasKey('merchant_id', $result);
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function failureCategories(): array
    {
        return [
            'invalid key' => [ApiKeyStatus::INVALID_KEY, 401],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 500],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null],
            'malformed' => [ApiKeyStatus::MALFORMED_RESPONSE, null],
            'other error' => [ApiKeyStatus::ERROR, 404],
        ];
    }

    // ── The response body never reaches the page ────────────────────────

    public function testUpstreamErrorTextIsNeverRendered(): void
    {
        // ApiKeyStatus already withholds the body, so the block has nothing
        // to leak — but it must also not go looking for one. A category with
        // a stray body-shaped key present still renders category wording only.
        $this->apiKeyStatus->method('refresh')->willReturn([
            'status' => ApiKeyStatus::INVALID_KEY,
            'code' => 401,
            'merchant' => null,
            'error_message' => 'api key not recognised for merchant acme',
        ]);

        $result = $this->build()->getApiKeyStatus();

        $this->assertStringNotContainsString('not recognised', (string)$result['message']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testVerificationIsLiveNotCached(): void
    {
        // An admin on this page is asking about the key in front of them, and
        // refresh() is also what feeds a corrected key forward to checkout.
        $this->apiKeyStatus->expects($this->once())->method('refresh')->willReturn(
            ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']]
        );
        $this->apiKeyStatus->expects($this->never())->method('getStatus');

        $this->build()->getApiKeyStatus();
    }
}
