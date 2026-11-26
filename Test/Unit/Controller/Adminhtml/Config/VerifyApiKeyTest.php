<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Controller\Adminhtml\Config\VerifyApiKey;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;
use Two\Gateway\Service\Merchant\CredentialsSave;

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

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    protected function setUp(): void
    {
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        // Staging is offered only on Two's infrastructure, or where it is stored.
        putenv('TWO_INTERNAL_ENVIRONMENTS');
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

        // The save's own rule for the parent scope: store view 7 is on website 3.
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn('3');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(7)->willReturn($store);
        $credentialsSave = new CredentialsSave(
            $this->createMock(ApiKeyStatus::class),
            new ApiKeyStatusMessage($brandRegistry),
            $this->createMock(MessageManager::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(EncryptorInterface::class),
            $storeManager
        );

        $controller = new VerifyApiKey(
            $context,
            new JsonFactory(),
            $this->apiKeyStatus,
            new ApiKeyStatusMessage($brandRegistry),
            $this->configRepository,
            $credentialsSave
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

    /**
     * @dataProvider merchantDetails
     * @param array<string,mixed> $verdict
     * @param array<string,mixed> $expected
     */
    public function testTheMerchantDetailsTravelWithTheVerdict(
        array $verdict,
        array $expected,
        string $description
    ): void {
        $this->apiKeyStatus->method('verifyCandidate')->willReturn($verdict);

        $response = $this->invoke(['api_key' => self::VALID_LENGTH_KEY]);

        $this->assertSame($expected, array_intersect_key($response, array_flip(
            ['merchant_id', 'merchant_short_name', 'definitive']
        )), $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: string}>
     */
    public static function merchantDetails(): array
    {
        $merchant = ['id' => 'abc-123', 'short_name' => 'acme'];
        return [
            'verified' => [
                ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => $merchant],
                ['definitive' => false, 'merchant_id' => 'abc-123', 'merchant_short_name' => 'acme'],
                'a verified candidate shows its merchant before save',
            ],
            'verified, no short name' => [
                ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']],
                ['definitive' => false, 'merchant_id' => 'abc-123', 'merchant_short_name' => ''],
                'a missing short name comes back empty',
            ],
            'rejected' => [
                ['status' => ApiKeyStatus::INVALID_KEY, 'code' => 401, 'merchant' => null],
                ['definitive' => true],
                'a rejected key clears the merchant and carries none',
            ],
            'service error' => [
                ['status' => ApiKeyStatus::SERVICE_ERROR, 'code' => 503, 'merchant' => null],
                ['definitive' => false],
                'an outage judged nothing about the key, so the merchant stays',
            ],
            'unreachable' => [
                ['status' => ApiKeyStatus::UNREACHABLE, 'code' => null, 'merchant' => null],
                ['definitive' => false],
                'a timeout judged nothing about the key, so the merchant stays',
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
        ?int $expectedScopeId,
        string $expectedScope,
        string $description
    ): void {
        // The resolved scope picks the environment host, so losing it would verify a
        // sandbox key against production and report it as rejected.
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::VALID_LENGTH_KEY, $expectedScopeId, null, $expectedScope)
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $response = $this->invoke(
            ['api_key' => self::VALID_LENGTH_KEY, 'scope' => $scope, 'scopeId' => $scopeId]
        );

        $this->assertTrue($response['verified'], $description);
    }

    /**
     * @dataProvider postedModes
     * @param array<string,mixed> $params
     */
    public function testTheCandidateIsVerifiedAgainstTheEnvironmentChosenOnTheForm(
        array $params,
        string $storedMode,
        ?string $expectedMode,
        string $description
    ): void {
        // TWO-27303: a null mode makes ApiKeyStatus read the saved environment.
        $this->configRepository->method('getStoredMode')->with(null, 'default')->willReturn($storedMode);
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::VALID_LENGTH_KEY, null, $expectedMode, 'default')
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $response = $this->invoke(['api_key' => self::VALID_LENGTH_KEY] + $params);

        $this->assertTrue($response['verified'], $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string, 2: string|null, 3: string}>
     */
    public static function postedModes(): array
    {
        return [
            'env switched, unsaved' => [['mode' => 'production'], 'sandbox', 'production', 'the dropdown value is used, not the saved one'],
            'env unchanged' => [['mode' => 'sandbox'], 'sandbox', 'sandbox', 'the dropdown value is used when it matches the saved one'],
            'staging, offered' => [['mode' => 'staging'], 'staging', 'staging', 'staging is used where the dropdown offers it'],
            'staging, not offered' => [['mode' => 'staging'], 'sandbox', null, 'a shop not offered staging never reaches its host'],
            'mode missing' => [[], 'sandbox', null, 'no dropdown value falls back to the saved environment'],
            'mode empty' => [['mode' => ''], 'sandbox', null, 'an empty value falls back to the saved environment'],
            'unknown mode' => [['mode' => 'development'], 'sandbox', null, 'an unknown value falls back to the saved environment'],
            'not canonical' => [['mode' => 'Production'], 'sandbox', null, 'only the stored spelling is accepted'],
            'not a string' => [['mode' => ['production']], 'sandbox', null, 'an array value falls back to the saved environment'],
        ];
    }

    /**
     * @dataProvider storedChecks
     * @param array<string,mixed> $params
     */
    public function testWithNoKeyTypedTheSavedKeyIsCheckedInTheChosenEnvironment(
        array $params,
        string $savedKey,
        ?array $expectedCall,
        string $description
    ): void {
        // TWO-27303: the saved key is read at the posted scope and never sent back.
        $this->configRepository->method('getApiKey')->with(3, 'website')->willReturn($savedKey);
        if ($expectedCall === null) {
            $this->apiKeyStatus->expects($this->never())->method('verifyCandidate');
        } else {
            $this->apiKeyStatus->expects($this->once())
                ->method('verifyCandidate')
                ->with(...$expectedCall)
                ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']]);
        }

        $response = $this->invoke(['scope' => 'websites', 'scopeId' => 3] + $params);

        $this->assertSame($expectedCall === null, $response['skipped'] ?? false, $description);
        $this->assertStringNotContainsString(self::VALID_LENGTH_KEY, (string)json_encode($response), $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string, 2: array<int,mixed>|null, 3: string}>
     */
    public static function storedChecks(): array
    {
        $saved = self::VALID_LENGTH_KEY;
        return [
            'env switched' => [['stored' => '1', 'mode' => 'production'], $saved, [$saved, 3, 'production', 'website'],
                'the saved key is checked against the environment chosen'],
            'unknown mode' => [['stored' => '1', 'mode' => 'development'], $saved, [$saved, 3, null, 'website'],
                'an unknown environment checks the saved key against the saved one'],
            'no stored key' => [['stored' => '1', 'mode' => 'production'], '', null,
                'with no key saved there is nothing to check'],
            'saved key padded' => [['stored' => '1', 'mode' => 'production'], " $saved ", [$saved, 3, 'production', 'website'],
                'the saved key is trimmed, as the save trims it'],
            'not asked' => [['mode' => 'production'], $saved, null,
                'an empty field without stored is skipped, as before'],
            'typed key wins' => [['stored' => '1', 'api_key' => 'typed-key-of-ample-length', 'mode' => 'production'], $saved,
                ['typed-key-of-ample-length', 3, 'production', 'website'], 'a typed key is checked, not the saved one'],
        ];
    }

    public function testAStoredCheckReturnsOnlyTheVerdict(): void
    {
        // TWO-27303: anyone allowed on the page could otherwise read any scope's merchant without its key.
        $this->configRepository->method('getApiKey')->willReturn(self::VALID_LENGTH_KEY);
        $this->apiKeyStatus->method('verifyCandidate')->willReturn(
            ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123', 'short_name' => 'acme']]
        );

        $response = $this->invoke(['scope' => 'websites', 'scopeId' => 3, 'stored' => '1', 'mode' => 'production']);

        $this->assertSame(
            ['verified' => true, 'status' => 'success', 'definitive' => false],
            array_intersect_key($response, array_flip(
                ['verified', 'status', 'definitive', 'merchant_id', 'merchant_short_name']
            ))
        );
    }

    /**
     * @dataProvider inheritedFields
     * @param array<string,mixed> $params
     * @param array<int,mixed> $expectedCall
     */
    public function testAnInheritedFieldIsJudgedAsTheSaveJudgesIt(
        array $params,
        array $expectedCall,
        string $description
    ): void {
        // TWO-27303: "Use Default" makes the save use the parent's value, so the check must too.
        $saved = [
            'default' => ['default-key-of-ample-length', 'sandbox'],
            'website' => ['website-key-of-ample-length', 'production'],
            'websites' => ['website-key-of-ample-length', 'production'],
            'store' => ['store-key-of-ample-length', 'staging'],
        ];
        $this->configRepository->method('getApiKey')->willReturnCallback(
            function ($id, $scope) use ($saved) {
                return $saved[$scope][0];
            }
        );
        $this->configRepository->method('getStoredMode')->willReturnCallback(
            function ($id, $scope) use ($saved) {
                return $saved[$scope][1];
            }
        );
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(...$expectedCall)
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $response = $this->invoke($params + ['scope' => 'websites', 'scopeId' => 3]);

        $this->assertTrue($response['verified'], $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: array<int,mixed>, 2: string}>
     */
    public static function inheritedFields(): array
    {
        $typed = 'typed-key-of-ample-length';
        $default = 'default-key-of-ample-length';
        $website = 'website-key-of-ample-length';
        return [
            'env inherited, parent differs' => [['api_key' => $typed, 'mode_inherit' => '1', 'mode' => 'production'],
                [$typed, 3, 'sandbox', 'website'], "a typed key is judged in the parent's environment, not this scope's"],
            'env inherited, stored check' => [['stored' => '1', 'mode_inherit' => '1'],
                [$website, 3, 'sandbox', 'website'], "the saved key is judged in the parent's environment"],
            'key inherited' => [['stored' => '1', 'key_inherit' => '1', 'mode' => 'production'],
                [$default, 3, 'production', 'website'], "the parent's key is judged, not this scope's"],
            'key inherited, field ignored' => [['api_key' => $typed, 'key_inherit' => '1', 'mode' => 'production'],
                [$default, 3, 'production', 'website'], 'an inherited key is never the typed one, as on save'],
            'both inherited' => [['stored' => '1', 'key_inherit' => '1', 'mode_inherit' => '1'],
                [$default, 3, 'sandbox', 'website'], "the parent's key in the parent's environment"],
            'store view inherits its website' => [
                ['scope' => 'stores', 'scopeId' => 7, 'stored' => '1', 'key_inherit' => '1', 'mode_inherit' => '1'],
                [$website, 7, 'production', 'store'], "a store view's parent is its website, not the default"],
            'default scope has no parent' => [
                ['scope' => 'default', 'scopeId' => 0, 'api_key' => $typed, 'mode_inherit' => '1', 'mode' => 'production'],
                [$typed, null, 'production', 'default'], 'the default scope has no box, so the posted environment applies'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int|null, 3: string, 4: string}>
     */
    public static function scopes(): array
    {
        return [
            'store view' => ['stores', 7, 7, 'store', 'a store-scope check uses that store'],
            'default' => ['default', 0, null, 'default', 'the default scope has no id'],
            'website' => ['websites', 3, 3, 'website', "a website uses its own environment, not the default's"],
            'store scope without an id' => ['stores', 0, null, 'default', 'a store scope with no id is the default scope'],
        ];
    }
}
