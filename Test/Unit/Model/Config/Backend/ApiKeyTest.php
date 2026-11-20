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
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Backend\ApiKey;
use Two\Gateway\Model\Config\Backend\Mode;
use Two\Gateway\Service\Merchant\CredentialsSave;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * Save-time guard on the API key field (TWO-25503).
 *
 * A rejected key must not replace a working one, and must not cost the
 * merchant the rest of the form either. Anything short of a
 * definitive rejection saves as submitted, so an outage cannot lock a
 * merchant out of configuring their first key.
 */
class ApiKeyTest extends TestCase
{
    private const CANDIDATE = 'candidate-key';

    private const KEY_PATH = 'two_general/general/api_key';
    private const MODE_PATH = 'two_general/general/mode';

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
        return new ApiKey(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->config(),
            $this->createMock(TypeListInterface::class),
            $this->encryptor(),
            $this->credentials(),
            null,
            null,
            $data
        );
    }

    /** @var array<string, string> config path => stored value, what the scope holds before the save */
    private $stored = [];

    /** @var CredentialsSave|null one per request, shared by the fields, as DI shares it */
    private $credentials;

    /**
     * @var array<string, array<string, string>>|null scope type => config path => effective value;
     *      null reads every scope from $stored
     */
    private $layers;

    private function config(): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function ($path, $scope = 'default') {
            if ($this->layers !== null) {
                return $this->layers[$scope][$path] ?? null;
            }
            return $this->stored[$path] ?? null;
        });

        return $config;
    }

    /** Store view 3 belongs to website 2. */
    private function storeManager(): StoreManagerInterface
    {
        $store = new class {
            public function getWebsiteId()
            {
                return 2;
            }
        };
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);

        return $manager;
    }

    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn ($value) => 'encrypted:' . $value);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn ($value) => strpos((string)$value, 'encrypted:') === 0 ? substr((string)$value, 10) : ''
        );

        return $encryptor;
    }

    private function credentials(): CredentialsSave
    {
        if ($this->credentials === null) {
            $brandRegistry = $this->createMock(BrandRegistryInterface::class);
            $brandRegistry->method('getProductName')->willReturn('Acme Pay');
            $this->credentials = new CredentialsSave(
                $this->apiKeyStatus,
                new ApiKeyStatusMessage($brandRegistry),
                $this->messageManager,
                $this->config(),
                $this->encryptor(),
                $this->storeManager()
            );
        }

        return $this->credentials;
    }

    /**
     * The environment field, posted in the same group as the key.
     */
    private function modeField(string $value, string $key, array $extra = []): Mode
    {
        return new Mode(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->config(),
            $this->createMock(TypeListInterface::class),
            $this->messageManager,
            $this->credentials(),
            null,
            null,
            $extra + [
                'value' => $value,
                'path' => self::MODE_PATH,
                'scope' => 'default',
                'fieldset_data' => ['mode' => $value, 'api_key' => $key],
            ]
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

        $this->assertFalse($model->isSaveAllowed(), $description . ': the stored key is kept, not overwritten (TWO-27108)');
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
        ?int $expectedScopeId,
        string $expectedScope,
        string $description
    ): void {
        $this->apiKeyStatus->expects($this->once())
            ->method('verifyCandidate')
            ->with(self::CANDIDATE, $expectedScopeId, null, $expectedScope)
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
     * @return array<string, array{0: string, 1: int, 2: int|null, 3: string, 4: string}>
     */
    public static function fieldScopes(): array
    {
        return [
            'store view' => ['stores', 7, 7, 'store', 'a store-scope save uses that store'],
            'singular store spelling' => ['store', 7, 7, 'store', 'the config layer uses both spellings'],
            'default' => ['default', 0, null, 'default', 'the default scope has no id'],
            'website' => ['websites', 3, 3, 'website', "a website uses its own environment, not the default's"],
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
            ->with(self::CANDIDATE, null, $expectedMode, 'default')
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

    /**
     * TWO-27108: the key and the environment are saved as one pair or not at
     * all; a key Two could not judge never replaces one that last verified;
     * the field beside them saves whatever happens to the pair.
     *
     * @dataProvider pairs
     */
    public function testTheKeyAndEnvironmentSaveAsOnePair(
        ?string $storedMode,
        string $typedKey,
        string $mode,
        ?string $verdict,
        ?string $lastDefinitive,
        array $expected,
        array $messages,
        string $description,
        string $storedKey = 'old-key'
    ): void {
        $this->stored = [self::KEY_PATH => 'encrypted:' . $storedKey, self::MODE_PATH => $storedMode];
        if ($storedMode === null) {
            $this->stored = [];
        }
        $checks = 0;
        $this->apiKeyStatus->method('verifyCandidate')->willReturnCallback(
            function () use (&$checks, $verdict) {
                $checks++;
                return ['status' => $verdict, 'code' => null, 'merchant' => null];
            }
        );
        $this->apiKeyStatus->method('lastDefinitiveStatus')->willReturn($lastDefinitive);
        $said = ['error' => 0, 'warning' => 0];
        $this->messageManager->method('addErrorMessage')->willReturnCallback(function () use (&$said) {
            $said['error']++;
            return $this->messageManager;
        });
        $this->messageManager->method('addWarningMessage')->willReturnCallback(function () use (&$said) {
            $said['warning']++;
            return $this->messageManager;
        });

        $stored = $this->saveSection([
            self::MODE_PATH => $this->modeField($mode, $typedKey),
            self::KEY_PATH => $this->build([
                'value' => $typedKey,
                'path' => self::KEY_PATH,
                'scope' => 'default',
                'fieldset_data' => ['mode' => $mode, 'api_key' => $typedKey],
            ]),
            self::SIBLING_PATH => $this->siblingField(),
        ]);

        $this->assertSame(self::SIBLING_VALUE, $stored[self::SIBLING_PATH] ?? null, $description . ': the sibling saves');
        unset($stored[self::SIBLING_PATH]);
        $this->assertSame($expected, $stored, $description);
        $this->assertSame($messages, $said, $description . ': messages');
        if ($verdict === null) {
            $this->assertSame(0, $checks, $description . ': nothing to ask Two');
        }
    }

    /**
     * @return array<string, array>
     */
    public static function pairs(): array
    {
        $ok = ApiKeyStatus::OK;
        $no = ApiKeyStatus::INVALID_KEY;
        $down = ApiKeyStatus::UNREACHABLE;
        $both = [self::MODE_PATH => 'production', self::KEY_PATH => 'encrypted:new-key'];
        $none = [];
        $quiet = ['error' => 0, 'warning' => 0];
        $error = ['error' => 1, 'warning' => 0];
        // stored environment, typed key, submitted environment, Two's verdict on the pair,
        // the stored pair's last definitive answer, what is written, messages, description
        return [
            'new pair accepted' => ['sandbox', 'new-key', 'production', $ok, null, $both, $quiet,
                'an accepted key is written with its environment'],
            'new pair rejected' => ['sandbox', 'new-key', 'production', $no, $ok, $none, $error,
                'a rejected key keeps the stored key AND environment'],
            'new pair unjudged, stored pair last verified' => ['sandbox', 'new-key', 'production', $down, $ok, $none, $error,
                'a key Two could not judge never replaces one that last verified'],
            'new pair unjudged, stored pair never verified' => ['sandbox', 'new-key', 'production', $down, null, $both,
                ['error' => 0, 'warning' => 1], 'nothing known good to protect: the pair saves, with a warning'],
            'environment only, stored key rejected there' => ['sandbox', '', 'production', $no, $ok, $none, $error,
                'switching environment checks the stored key there, and a rejection keeps both'],
            'environment only, stored key accepted there' => ['sandbox', '', 'production', $ok, null,
                [self::MODE_PATH => 'production'], $quiet, 'the environment saves; the stored key is not rewritten'],
            'environment only during an outage, stored pair last verified' => ['sandbox', '', 'production', $down, $ok,
                $none, $error, 'an outage cannot move a verified key to another environment'],
            'untouched save' => ['sandbox', '', 'sandbox', null, $ok, [self::MODE_PATH => 'sandbox'], $quiet,
                'nothing changed: nothing is asked, and nothing is refused'],
            'untouched save over a legacy stored environment' => ['prod', '', 'production', null, $ok,
                [self::MODE_PATH => 'production'], $quiet,
                'a legacy spelling of the same environment is unchanged, so an outage cannot refuse the save'],
            'refused environment with a new key' => ['sandbox', 'new-key', 'staging', null, $ok, $none,
                ['error' => 2, 'warning' => 0], 'a refused environment keeps the key typed beside it, and says so'],
            'malformed key' => ['sandbox', "new key", 'production', null, $ok, $none, $error,
                'a key with a space is never sent, and keeps both'],
            'untouched save over a stored key with stray whitespace' => ['sandbox', '', 'sandbox', null, $ok,
                [self::MODE_PATH => 'sandbox'], $quiet, 'a stored key is trimmed before it is compared, and never refused for its form',
                " old-key\t"],
            'environment only over a stored key with an inner space' => ['sandbox', '', 'production', $ok, null,
                [self::MODE_PATH => 'production'], $quiet, 'a stored key is Two\'s to judge, never refused for its form',
                'old key'],
            'no key anywhere' => [null, '', 'production', null, null, [self::MODE_PATH => 'production'], $error,
                'with no key to protect the environment saves, and the merchant is asked for a key'],
        ];
    }

    /**
     * TWO-27108: "Use Default" on either half at a website or store view is a
     * change of that half to the parent's value, judged by the same rule.
     * Core deletes an inherited field without beforeSave(), so a pair that must
     * be kept refuses the whole save (the delete throws, and nothing is saved).
     *
     * Website 2 holds sandbox + its own key; the default scope holds production
     * + the default key. Store view 3, in website 2, holds production + the
     * default key of its own.
     *
     * @dataProvider inheritedPairs
     */
    public function testUseDefaultOnEitherHalfFollowsThePairRule(
        string $scope,
        int $scopeId,
        ?string $typedKey,
        ?string $mode,
        ?string $verdict,
        $expected,
        ?string $verifiedMode,
        string $description
    ): void {
        $this->layers = [
            'default' => [self::KEY_PATH => 'encrypted:default-key', self::MODE_PATH => 'production'],
            'websites' => [self::KEY_PATH => 'encrypted:website-key', self::MODE_PATH => 'sandbox'],
            'stores' => [self::KEY_PATH => 'encrypted:default-key', self::MODE_PATH => 'production'],
        ];
        $asked = [];
        $this->apiKeyStatus->method('verifyCandidate')->willReturnCallback(
            function ($key, $id, $mode) use (&$asked, $verdict) {
                $asked[] = [$key, $mode];
                return ['status' => $verdict, 'code' => null, 'merchant' => null];
            }
        );
        $this->apiKeyStatus->method('lastDefinitiveStatus')->willReturn(ApiKeyStatus::OK);

        // null = "Use Default" ticked: the input is disabled, so nothing is posted.
        $groups = ['general' => ['fields' => [
            'mode' => $mode === null ? ['inherit' => 1] : ['value' => $mode],
            'api_key' => $typedKey === null ? ['inherit' => 1] : ['value' => $typedKey],
        ]]];
        $data = [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'groups' => $groups,
            'group_id' => 'general',
            'fieldset_data' => ['mode' => $mode, 'api_key' => $typedKey],
        ];
        $modeModel = $this->modeField((string)$mode, (string)$typedKey, $data);
        $keyModel = $this->build(['value' => (string)$typedKey, 'path' => self::KEY_PATH] + $data);

        $result = $this->saveWithUseDefault([
            self::MODE_PATH => [$modeModel, $mode === null],
            self::KEY_PATH => [$keyModel, $typedKey === null],
        ]);

        $this->assertSame($expected, $result, $description);
        if ($verifiedMode !== null) {
            $this->assertSame($verifiedMode, $asked[0][1] ?? null, $description . ': the environment the key is checked against');
        }
        if ($verdict === null) {
            $this->assertSame([], $asked, $description . ': nothing to ask Two');
        }
    }

    /**
     * Mirrors core's section save: the inherited fields' delete runs first and
     * an exception from it rolls everything back; then the rest are saved.
     *
     * @param array<string, array{0: Value, 1: bool}> $fields path => [model, "Use Default" ticked]
     * @return array{written: array<string, mixed>, deleted: list<string>}|string
     */
    private function saveWithUseDefault(array $fields)
    {
        $deleted = [];
        try {
            foreach ($fields as $path => [$model, $inherited]) {
                if ($inherited) {
                    $model->beforeDelete();
                    $deleted[] = $path;
                }
            }
        } catch (LocalizedException $e) {
            return 'rolled back';
        }
        $written = [];
        foreach ($fields as $path => [$model, $inherited]) {
            if (!$inherited) {
                $model->beforeSave();
                if ($model->isSaveAllowed()) {
                    $written[$path] = $model->getValue();
                }
            }
        }

        return ['written' => $written, 'deleted' => $deleted];
    }

    /**
     * @return array<string, array>
     */
    public static function inheritedPairs(): array
    {
        $ok = ApiKeyStatus::OK;
        $no = ApiKeyStatus::INVALID_KEY;
        // scope, scope id, typed key (null = Use Default), environment (null = Use Default),
        // Two's verdict, the result, the environment the key is checked against, description
        return [
            'website: a new pair accepted' => ['websites', 2, 'new-key', 'production', $ok,
                ['written' => [self::MODE_PATH => 'production', self::KEY_PATH => 'encrypted:new-key'], 'deleted' => []],
                'production', 'a website saves its own pair by the same rule'],
            'website: a new pair rejected' => ['websites', 2, 'new-key', 'production', $no,
                ['written' => [], 'deleted' => []], 'production', 'a website keeps its pair on a rejection'],
            'website: environment to Use Default, key blank, rejected there' => ['websites', 2, '', null, $no,
                'rolled back', 'production', 'the website key is checked against the inherited environment, and a rejection stops the delete'],
            'website: environment to Use Default, key blank, accepted there' => ['websites', 2, '', null, $ok,
                ['written' => [], 'deleted' => [self::MODE_PATH]], 'production', 'an inherited environment the key works in is deleted'],
            'website: environment to Use Default, new key pasted' => ['websites', 2, 'default-key-2', null, $ok,
                ['written' => [self::KEY_PATH => 'encrypted:default-key-2'], 'deleted' => [self::MODE_PATH]],
                'production', 'a pasted key is checked against the inherited environment, not the old one'],
            'website: key to Use Default, environment changed, accepted' => ['websites', 2, null, 'production', $ok,
                ['written' => [self::MODE_PATH => 'production'], 'deleted' => [self::KEY_PATH]],
                'production', 'the inherited key is checked against the new environment'],
            'website: key to Use Default, environment kept, rejected' => ['websites', 2, null, 'sandbox', $no,
                'rolled back', 'sandbox', 'the inherited key is rejected in the website environment, so nothing is saved'],
            'website: both to Use Default' => ['websites', 2, null, null, $ok,
                ['written' => [], 'deleted' => [self::MODE_PATH, self::KEY_PATH]], 'production',
                'inheriting the whole pair checks the parent pair'],
            'store view: environment to Use Default, rejected there' => ['stores', 3, '', null, $no,
                'rolled back', 'sandbox', 'a store view inherits from its website, and the rule holds'],
            'store view: key to Use Default, accepted' => ['stores', 3, null, 'sandbox', $ok,
                ['written' => [self::MODE_PATH => 'sandbox'], 'deleted' => [self::KEY_PATH]],
                'sandbox', 'a store view takes its website key, checked against the environment being saved'],
        ];
    }
}
