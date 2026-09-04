<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * The single owner of API-key verdict wording, shared by the settings-page
 * renderer, the live verification endpoint and the save-time guard.
 *
 * The regression this pins: every non-200 outcome used to read "the key is
 * not valid", so an admin whose store could not reach the API went looking
 * for the wrong fix. Each category must read differently, and none may
 * carry an upstream response body.
 */
class ApiKeyStatusMessageTest extends TestCase
{
    private function build(): ApiKeyStatusMessage
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        return new ApiKeyStatusMessage($brandRegistry);
    }

    /**
     * @dataProvider categories
     * @param string[] $expectedFragments
     */
    public function testEachCategoryHasItsOwnWordingAndSeverity(
        string $category,
        ?int $code,
        string $expectedStatus,
        array $expectedFragments,
        string $description
    ): void {
        $described = $this->build()->describe(
            ['status' => $category, 'code' => $code, 'merchant' => null]
        );

        $this->assertSame($expectedStatus, $described['status'], $description);
        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, (string)$described['message'], $description);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: string, 3: string[], 4: string}>
     */
    public static function categories(): array
    {
        return [
            'ok' => [
                ApiKeyStatus::OK,
                200,
                'success',
                ['API key is valid'],
                'a verified key',
            ],
            'not configured' => [
                ApiKeyStatus::NOT_CONFIGURED,
                null,
                'warning',
                ['API key is missing'],
                'no key saved is a warning, not a service failure',
            ],
            'invalid key' => [
                ApiKeyStatus::INVALID_KEY,
                401,
                'error',
                ['rejected', 'Acme Pay'],
                'the only category where replacing the key is the right advice',
            ],
            'service error' => [
                ApiKeyStatus::SERVICE_ERROR,
                503,
                'error',
                ['may be fine', '503'],
                'a 5xx exonerates the key and quotes the status',
            ],
            'unreachable' => [
                ApiKeyStatus::UNREACHABLE,
                null,
                'error',
                ['could not be reached', 'connection'],
                'no HTTP exchange completed is a connection problem',
            ],
            'malformed response' => [
                ApiKeyStatus::MALFORMED_RESPONSE,
                null,
                'error',
                ['unexpected response'],
                'a 2xx without a merchant id is unconfirmed, not rejected',
            ],
            'other error' => [
                ApiKeyStatus::ERROR,
                418,
                'error',
                ['could not be verified', '418'],
                'any other non-2xx quotes its status',
            ],
            'unknown category' => [
                'something-new',
                null,
                'error',
                ['could not be verified'],
                'an unrecognised category degrades to the generic error',
            ],
        ];
    }

    public function testTheFailureCategoriesAllReadDifferently(): void
    {
        $service = $this->build();
        $messages = [];
        foreach ([
            [ApiKeyStatus::INVALID_KEY, 401],
            [ApiKeyStatus::SERVICE_ERROR, 500],
            [ApiKeyStatus::UNREACHABLE, null],
            [ApiKeyStatus::MALFORMED_RESPONSE, null],
            [ApiKeyStatus::ERROR, 404],
        ] as [$category, $code]) {
            $messages[] = (string)$service->describe(
                ['status' => $category, 'code' => $code, 'merchant' => null]
            )['message'];
        }

        $this->assertCount(5, array_unique($messages), 'no two failure categories may collapse onto one message');
    }

    public function testSuccessSurfacesTheResolvedMerchant(): void
    {
        $described = $this->build()->describe([
            'status' => ApiKeyStatus::OK,
            'code' => 200,
            'merchant' => ['id' => 'abc-123', 'short_name' => 'acme'],
        ]);

        $this->assertSame('abc-123', $described['merchant_id']);
        $this->assertSame('acme', $described['merchant_short_name']);
    }

    /**
     * @dataProvider failureCategories
     */
    public function testAFailureNeverCarriesAMerchantOrTheResponseBody(string $category, string $description): void
    {
        $described = $this->build()->describe([
            'status' => $category,
            'code' => 401,
            'merchant' => null,
            'error_message' => 'api key not recognised for merchant acme',
        ]);

        // A stale merchant id beside a failure is how a broken key used to
        // look like a cached success.
        $this->assertArrayNotHasKey('merchant_id', $described, $description);
        $this->assertStringNotContainsString('not recognised', (string)$described['message'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failureCategories(): array
    {
        return [
            'invalid key' => [ApiKeyStatus::INVALID_KEY, 'a rejected key'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 'an erroring service'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, 'an unreachable service'],
            'malformed' => [ApiKeyStatus::MALFORMED_RESPONSE, 'an unexpected response'],
            'other error' => [ApiKeyStatus::ERROR, 'any other non-2xx'],
        ];
    }
}
