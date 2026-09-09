<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Backend\ApiKey;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * Save-time guard on the API key field (TWO-25503).
 *
 * A rejected key must not replace a working one, and must not cost the
 * merchant the rest of the form either (ABN-495). Anything short of a
 * definitive rejection saves as submitted, so an outage cannot lock a
 * merchant out of configuring their first key.
 */
class ApiKeyTest extends TestCase
{
    private const CANDIDATE = 'candidate-key';

    private const KEY_PATH = 'two_general/general/api_key';

    private const SIBLING_PATH = 'two_general/general/vendor_site_name';

    private const SIBLING_VALUE = 'Northwind Supplies';

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    /** @var MessageManager|\PHPUnit\Framework\MockObject\MockObject */
    private $messageManager;

    protected function setUp(): void
    {
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $this->messageManager = $this->createMock(MessageManager::class);
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
            $this->messageManager,
            null,
            null,
            $data
        );
    }

    /**
     * A plain text field posted in the same group as the key.
     */
    private function siblingField(): Value
    {
        return new Value(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            ['value' => self::SIBLING_VALUE, 'path' => self::SIBLING_PATH]
        );
    }

    /**
     * Mirrors AbstractDb::save(): beforeSave() runs, then only a model that left its own save allowed is written.
     *
     * @param array<string, Value> $models keyed by config path
     * @return array<string, mixed> what storage is left holding
     */
    private function saveSection(array $models): array
    {
        $stored = [];
        foreach ($models as $path => $model) {
            $model->beforeSave();
            if ($model->isSaveAllowed()) {
                $stored[$path] = $model->getValue();
            }
        }

        return $stored;
    }

    private function stubVerdict(string $category, ?int $code = null): void
    {
        $this->apiKeyStatus->method('verifyCandidate')->willReturn(
            ['status' => $category, 'code' => $code, 'merchant' => null]
        );
    }

    public function testARejectedKeyIsDroppedAndTheFieldSubmittedBesideItStillSaves(): void
    {
        // Given a key the API rejects and a vendor-name edit in the same
        // request; when the section is saved; then the working key survives
        // and the vendor name lands.
        $this->stubVerdict(ApiKeyStatus::INVALID_KEY, 401);
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(function ($message) {
                return strpos((string)$message, 'rejected') !== false;
            }));

        $stored = $this->saveSection([
            self::KEY_PATH => $this->build([
                'value' => self::CANDIDATE,
                'scope' => 'default',
                'path' => self::KEY_PATH,
            ]),
            self::SIBLING_PATH => $this->siblingField(),
        ]);

        $this->assertArrayNotHasKey(self::KEY_PATH, $stored, 'a rejected key must not be written');
        $this->assertSame(self::SIBLING_VALUE, $stored[self::SIBLING_PATH] ?? null, 'the sibling field must save');
    }

    /**
     * @dataProvider nonBlockingVerdicts
     */
    public function testAnUnconfirmedKeyAndTheFieldBesideItBothSave(
        string $category,
        ?int $code,
        string $description
    ): void {
        // We cannot tell "bad key" from "our side is down", and blocking would
        // stop a merchant configuring a first key during an outage.
        $this->stubVerdict($category, $code);
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $stored = $this->saveSection([
            self::KEY_PATH => $this->build([
                'value' => self::CANDIDATE,
                'scope' => 'default',
                'path' => self::KEY_PATH,
            ]),
            self::SIBLING_PATH => $this->siblingField(),
        ]);

        $this->assertSame('encrypted:' . self::CANDIDATE, $stored[self::KEY_PATH] ?? null, $description);
        $this->assertSame(self::SIBLING_VALUE, $stored[self::SIBLING_PATH] ?? null, $description);
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

    /**
     * setup:di:compile resolves resource/resourceCollection's inherited
     * object bindings (core's Proxy wiring on Value/Encrypted) by matching
     * the constructor parameter's type hint. An untyped resourceCollection
     * param compiled to a broken literal instead of the resolved object,
     * fataling on every instantiation and taking down the whole admin
     * config section that field lives in — a real production incident,
     * reproduced live via setup:di:compile + reflection on the compiled
     * arguments. The test double for Encrypted isn't a reliable reference
     * (it's untyped too), so this pins the exact real Magento types instead.
     */
    public function testResourceParamsKeepTheTypeHintsDiCompileNeedsToResolveThem(): void
    {
        $params = (new ReflectionMethod(ApiKey::class, '__construct'))->getParameters();
        $byName = [];
        foreach ($params as $param) {
            $byName[$param->getName()] = (string)$param->getType();
        }

        self::assertSame(
            '?Magento\Framework\Model\ResourceModel\AbstractResource',
            $byName['resource'],
            '$resource must stay typed, matching Encrypted/Value'
        );
        self::assertSame(
            '?Magento\Framework\Data\Collection\AbstractDb',
            $byName['resourceCollection'],
            '$resourceCollection must stay typed, matching Encrypted/Value'
        );
    }
}
