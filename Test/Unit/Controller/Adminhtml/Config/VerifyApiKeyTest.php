<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Controller\Adminhtml\Config\VerifyApiKey;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * The live API-key check behind the admin settings page.
 *
 * Verifies a candidate that has not been saved, so the verdict must never
 * be cached and the candidate must never come back in the response.
 */
class VerifyApiKeyTest extends TestCase
{
    private const VALID_LENGTH_KEY = 'candidate-key-of-ample-length';

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    protected function setUp(): void
    {
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function invoke(array $params): array
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            function ($key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        $controller = new VerifyApiKey(
            $context,
            new JsonFactory(),
            $this->apiKeyStatus,
            new ApiKeyStatusMessage($brandRegistry)
        );

        return (array)$controller->execute()->getData();
    }

    /**
     * @dataProvider tooShortKeys
     */
    public function testAKeyBelowTheMinimumLengthIsNotSentUpstream(string $apiKey, string $description): void
    {
        // Given a half-typed key; when the endpoint is hit; then no round trip.
        $this->apiKeyStatus->expects($this->never())->method('verifyCandidate');

        $response = $this->invoke(['api_key' => $apiKey]);

        $this->assertTrue($response['skipped'], $description);
        $this->assertArrayNotHasKey('status', $response, $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tooShortKeys(): array
    {
        return [
            'no key at all' => ['', 'an empty field'],
            'one character' => ['a', 'the first keystroke'],
            'one below the floor' => [str_repeat('a', VerifyApiKey::MIN_KEY_LENGTH - 1), 'just under the floor'],
            'whitespace padded' => [
                ' ' . str_repeat('a', VerifyApiKey::MIN_KEY_LENGTH - 1) . ' ',
                'padding does not make a key long enough',
            ],
        ];
    }

    public function testAKeyAtTheMinimumLengthIsVerified(): void
    {
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']]);

        $response = $this->invoke(['api_key' => str_repeat('a', VerifyApiKey::MIN_KEY_LENGTH)]);

        $this->assertTrue($response['verified']);
    }

    /**
     * @dataProvider verdicts
     */
    public function testTheVerdictIsPassedThroughWithItsWording(
        string $category,
        ?int $code,
        bool $expectedVerified,
        string $expectedStatus,
        string $expectedFragment,
        string $description
    ): void {
        $this->apiKeyStatus->method('verifyCandidate')->willReturn(
            ['status' => $category, 'code' => $code, 'merchant' => ['id' => 'abc-123']]
        );

        $response = $this->invoke(['api_key' => self::VALID_LENGTH_KEY]);

        $this->assertSame($expectedVerified, $response['verified'], $description);
        $this->assertSame($expectedStatus, $response['status'], $description);
        $this->assertStringContainsString($expectedFragment, $response['message'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: bool, 3: string, 4: string, 5: string}>
     */
    public static function verdicts(): array
    {
        return [
            'verified' => [
                ApiKeyStatus::OK,
                200,
                true,
                'success',
                'API key is valid',
                'a candidate that verifies',
            ],
            'rejected' => [
                ApiKeyStatus::INVALID_KEY,
                401,
                false,
                'error',
                'rejected',
                'a candidate rejected upstream',
            ],
            'service error' => [
                ApiKeyStatus::SERVICE_ERROR,
                503,
                false,
                'error',
                'may be fine',
                'an erroring service is not a verdict on the key',
            ],
        ];
    }

    public function testTheCandidateKeyIsNeverEchoedBack(): void
    {
        $this->apiKeyStatus->method('verifyCandidate')->willReturn(
            ['status' => ApiKeyStatus::INVALID_KEY, 'code' => 401, 'merchant' => null]
        );

        $response = $this->invoke(['api_key' => self::VALID_LENGTH_KEY]);

        $this->assertStringNotContainsString(
            self::VALID_LENGTH_KEY,
            (string)json_encode($response),
            'a secret posted to an admin endpoint must not come back in its response'
        );
    }

    /**
     * @dataProvider scopes
     */
    public function testThePostedScopeSelectsTheStoreTheCandidateIsVerifiedAgainst(
        string $scope,
        int $scopeId,
        ?int $expectedStoreId,
        string $description
    ): void {
        // The store id picks the environment host, so losing it would verify a
        // sandbox key against production and report it as rejected.
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::VALID_LENGTH_KEY, $expectedStoreId)
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $response = $this->invoke(
            ['api_key' => self::VALID_LENGTH_KEY, 'scope' => $scope, 'scopeId' => $scopeId]
        );

        $this->assertTrue($response['verified'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int|null, 3: string}>
     */
    public static function scopes(): array
    {
        return [
            'store view' => ['stores', 7, 7, 'a store-scope check uses that store'],
            'default' => ['default', 0, null, 'the default scope has no store'],
            'website' => ['websites', 3, null, 'website scope stays on the default environment'],
            'store scope without an id' => ['stores', 0, null, 'a store scope with no id is the default scope'],
        ];
    }
}
