<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Backend\ApiKey;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * Save-time guard on the API key field (TWO-25503).
 *
 * A rejected key must not replace a working one. Anything short of a
 * definitive rejection must still save, so an outage cannot lock a
 * merchant out of configuring their first key.
 */
class ApiKeyTest extends TestCase
{
    private const CANDIDATE = 'candidate-key';

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    protected function setUp(): void
    {
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function build(array $data): ApiKey
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(
            function ($value) {
                return 'encrypted:' . $value;
            }
        );

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        return new ApiKey(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $encryptor,
            $this->apiKeyStatus,
            new ApiKeyStatusMessage($brandRegistry),
            null,
            null,
            $data
        );
    }

    private function stubVerdict(string $category, ?int $code = null): void
    {
        $this->apiKeyStatus->method('verifyCandidate')->willReturn(
            ['status' => $category, 'code' => $code, 'merchant' => null]
        );
    }

    public function testARejectedKeyAbortsTheSaveAndLeavesTheStoredKeyAlone(): void
    {
        // Given a key the API rejects; when the section is saved; then the save
        // fails and nothing is written over the working key.
        $this->stubVerdict(ApiKeyStatus::INVALID_KEY, 401);
        $model = $this->build(['value' => self::CANDIDATE, 'scope' => 'default']);

        try {
            $model->beforeSave();
            $this->fail('a rejected key must abort the save');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }

        // The parent encrypts the value on the way to storage; an untouched
        // plaintext value is the proof that nothing was written.
        $this->assertSame(self::CANDIDATE, $model->getValue());
    }

    /**
     * @dataProvider nonBlockingVerdicts
     */
    public function testAnUnconfirmedKeyStillSaves(string $category, ?int $code, string $description): void
    {
        // We cannot tell "bad key" from "our side is down", and blocking would
        // stop a merchant configuring a first key during an outage.
        $this->stubVerdict($category, $code);
        $model = $this->build(['value' => self::CANDIDATE, 'scope' => 'default']);

        $model->beforeSave();

        $this->assertSame('encrypted:' . self::CANDIDATE, $model->getValue(), $description);
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: string}>
     */
    public static function nonBlockingVerdicts(): array
    {
        return [
            'verified' => [ApiKeyStatus::OK, 200, 'a verified key saves'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null, 'no HTTP exchange completed must not block'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 503, 'an erroring service must not block'],
            'other error' => [ApiKeyStatus::ERROR, 404, 'an unclassified error must not block'],
            'malformed' => [ApiKeyStatus::MALFORMED_RESPONSE, null, 'an unconfirmable response must not block'],
        ];
    }

    /**
     * @dataProvider unchangedSubmissions
     */
    public function testAnUnchangedKeyIsNotVerified(string $value, string $description): void
    {
        // The obscured placeholder and a blank field both mean the stored key
        // is not being replaced, so there is nothing to verify.
        $this->apiKeyStatus->expects($this->never())->method('verifyCandidate');
        $model = $this->build(['value' => $value, 'scope' => 'default']);

        $model->beforeSave();

        $this->assertSame($value, $model->getValue(), $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unchangedSubmissions(): array
    {
        return [
            'obscured placeholder' => ['******', 'the rendered stand-in for the stored key'],
            'empty field' => ['', 'nothing submitted'],
        ];
    }

    /**
     * @dataProvider fieldScopes
     */
    public function testTheFieldScopeSelectsTheStoreTheCandidateIsVerifiedAgainst(
        string $scope,
        int $scopeId,
        ?int $expectedStoreId,
        string $description
    ): void {
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::CANDIDATE, $expectedStoreId, null)
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $model = $this->build([
            'value' => self::CANDIDATE,
            'scope' => $scope,
            'scope_id' => $scopeId,
        ]);

        $model->beforeSave();

        $this->assertSame('encrypted:' . self::CANDIDATE, $model->getValue(), $description);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int|null, 3: string}>
     */
    public static function fieldScopes(): array
    {
        return [
            'store view' => ['stores', 7, 7, 'a store-scope save uses that store'],
            'singular store spelling' => ['store', 7, 7, 'the config layer uses both spellings'],
            'default' => ['default', 0, null, 'the default scope has no store'],
            'website' => ['websites', 3, null, 'website scope stays on the default environment'],
        ];
    }

    /**
     * Switching environment and pasting that environment's key is ONE admin
     * action. Verifying against the committed mode would call the OLD
     * environment, get a 401 and fail the whole section save on a key that is
     * perfectly good.
     *
     * @dataProvider submittedModes
     */
    public function testTheCandidateIsVerifiedAgainstTheModeBeingSaved(
        array $fieldsetData,
        ?string $expectedMode,
        string $description
    ): void {
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::CANDIDATE, null, $expectedMode)
            ->willReturn(['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]);

        $model = $this->build([
            'value' => self::CANDIDATE,
            'scope' => 'default',
            'fieldset_data' => $fieldsetData,
        ]);

        $model->beforeSave();

        $this->assertSame('encrypted:' . self::CANDIDATE, $model->getValue(), $description);
    }

    /**
     * @return array<string, array{0: array, 1: string|null, 2: string}>
     */
    public static function submittedModes(): array
    {
        return [
            'switching to production' => [
                ['mode' => 'production', 'api_key' => self::CANDIDATE],
                'production',
                'the environment being saved is the one the key is checked against',
            ],
            'switching to sandbox' => [
                ['mode' => 'sandbox', 'api_key' => self::CANDIDATE],
                'sandbox',
                'the same in the other direction',
            ],
            'mode inherited, not submitted' => [
                ['api_key' => self::CANDIDATE],
                null,
                'nothing submitted leaves the stored environment to decide',
            ],
        ];
    }
}
