<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Backend\App\Action\Context as ActionContext;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Brand\DescriptorBackedBrandRegistry;
use Two\Gateway\Controller\Adminhtml\Config\VerifyApiKey;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Model\Brand\Loader;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\Backend\ApiKey;
use Two\Gateway\Model\Config\Backend\Mode;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;
use Two\Gateway\Service\Merchant\CredentialsSave;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The key a merchant provides, changes or saves for a store is the key that
 * reaches the API, at the host for the environment they chose. Row
 * descriptions start with the case number in the ticket that added this file.
 *
 * Each case starts from what is saved in admin config, takes one merchant
 * action, and checks the requests as they leave for the network. The real
 * Adapter, default translator, config Repository, brand registry over the
 * shipped etc/brand.xml, ApiKeyStatus, save rule (CredentialsSave with the
 * Mode and ApiKey backend models) and Verify button controller run; only
 * config storage and the HTTP transport are fakes. Stores 1 and 2 are in
 * website 1, store 3 in website 2.
 */
class ApiKeyScenarioTest extends TestCase
{
    private const PRODUCTION = 'https://api.two.inc';
    private const SANDBOX = 'https://api.sandbox.two.inc';
    private const VERIFY_ENDPOINT = '/v1/merchant/verify_api_key';
    private const ORDER_ENDPOINT = '/v1/order';

    private const KEY_A = 'key-A-1234567890abcdef';
    private const KEY_B = 'key-B-1234567890abcdef';
    private const KEY_C = 'key-C-1234567890abcdef';
    private const KEY_K = 'key-K-1234567890abcdef';
    private const KEY_P = 'key-P-1234567890abcdef';
    private const KEY_S = 'key-S-1234567890abcdef';
    private const KEY_V = 'key-V-1234567890abcdef';
    private const KEY_W = 'key-W-1234567890abcdef';

    /** @var list<array{method: string, url: string, headers: array<string, string>}> requests as they left */
    private $sent = [];

    /** @var ScopedConfigStore */
    private $config;

    /** @var Adapter */
    private $adapter;

    /** @var ApiKeyStatus */
    private $apiKeyStatus;

    /** @var ApiKeyStatusMessage */
    private $statusMessage;

    /** @var CredentialsSave */
    private $credentialsSave;

    /** @var Repository */
    private $repository;

    /**
     * saved config | admin save [scope, id, key, mode] | verification [key, host]
     * | saved config afterwards | store 1's next order call [key, host]
     *
     * A key of '' leaves the field blank, which keeps the stored key; a null
     * key or mode ticks "Use Default" (or "Use Website") on that field.
     */
    public static function saves(): array
    {
        $sandboxA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'sandbox']];
        $productionA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'production']];
        $websiteB = ['websites' => [1 => ['api_key' => self::KEY_B, 'mode' => 'production']]];

        return [
            '1: first key, sandbox chosen' => [
                [], ['default', 0, self::KEY_K, 'sandbox'], [self::KEY_K, self::SANDBOX],
                ['default' => ['api_key' => self::KEY_K, 'mode' => 'sandbox']], [self::KEY_K, self::SANDBOX],
            ],
            '2: first key, production chosen' => [
                [], ['default', 0, self::KEY_K, 'production'], [self::KEY_K, self::PRODUCTION],
                ['default' => ['api_key' => self::KEY_K, 'mode' => 'production']], [self::KEY_K, self::PRODUCTION],
            ],
            '2: first key at website scope, production chosen' => [
                [], ['websites', 1, self::KEY_K, 'production'], [self::KEY_K, self::PRODUCTION],
                ['websites' => [1 => ['api_key' => self::KEY_K, 'mode' => 'production']]], [self::KEY_K, self::PRODUCTION],
            ],
            '3: changing the key in sandbox' => [
                $sandboxA, ['default', 0, self::KEY_B, 'sandbox'], [self::KEY_B, self::SANDBOX],
                ['default' => ['api_key' => self::KEY_B, 'mode' => 'sandbox']], [self::KEY_B, self::SANDBOX],
            ],
            '3: changing the key in production' => [
                $productionA, ['default', 0, self::KEY_B, 'production'], [self::KEY_B, self::PRODUCTION],
                ['default' => ['api_key' => self::KEY_B, 'mode' => 'production']], [self::KEY_B, self::PRODUCTION],
            ],
            '4: switching sandbox to production with a production key' => [
                $sandboxA, ['default', 0, self::KEY_P, 'production'], [self::KEY_P, self::PRODUCTION],
                ['default' => ['api_key' => self::KEY_P, 'mode' => 'production']], [self::KEY_P, self::PRODUCTION],
            ],
            '4: switching production to sandbox with a sandbox key' => [
                $productionA, ['default', 0, self::KEY_S, 'sandbox'], [self::KEY_S, self::SANDBOX],
                ['default' => ['api_key' => self::KEY_S, 'mode' => 'sandbox']], [self::KEY_S, self::SANDBOX],
            ],
            '4: switching to production at website scope' => [
                ['websites' => [1 => ['api_key' => self::KEY_A, 'mode' => 'sandbox']]],
                ['websites', 1, self::KEY_P, 'production'], [self::KEY_P, self::PRODUCTION],
                ['websites' => [1 => ['api_key' => self::KEY_P, 'mode' => 'production']]], [self::KEY_P, self::PRODUCTION],
            ],
            '4 (guards TWO-27302): switching environment without changing the key checks the saved key against it' => [
                $sandboxA, ['default', 0, '', 'production'], [self::KEY_A, self::PRODUCTION],
                ['default' => ['api_key' => self::KEY_A, 'mode' => 'production']], [self::KEY_A, self::PRODUCTION],
            ],
            '8: first key saved only at website scope, nothing at Default' => [
                [], ['websites', 1, self::KEY_W, null], [self::KEY_W, self::SANDBOX],
                ['websites' => [1 => ['api_key' => self::KEY_W]]], [self::KEY_W, self::SANDBOX],
            ],
            '8: first key saved only at store view scope, nothing at Default' => [
                [], ['stores', 1, self::KEY_V, null], [self::KEY_V, self::SANDBOX],
                ['stores' => [1 => ['api_key' => self::KEY_V]]], [self::KEY_V, self::SANDBOX],
            ],
            '9: a new key at website scope while Default has a different key' => [
                $sandboxA, ['websites', 1, self::KEY_K, null], [self::KEY_K, self::SANDBOX],
                $sandboxA + ['websites' => [1 => ['api_key' => self::KEY_K]]], [self::KEY_K, self::SANDBOX],
            ],
            '11: website scope, production chosen, Default on sandbox' => [
                $sandboxA, ['websites', 1, self::KEY_K, 'production'], [self::KEY_K, self::PRODUCTION],
                $sandboxA + ['websites' => [1 => ['api_key' => self::KEY_K, 'mode' => 'production']]], [self::KEY_K, self::PRODUCTION],
            ],
            '14 (guards TWO-27302): only the environment at a website checks the inherited key against it' => [
                $sandboxA, ['websites', 1, null, 'production'], [self::KEY_A, self::PRODUCTION],
                $sandboxA + ['websites' => [1 => ['mode' => 'production']]], [self::KEY_A, self::PRODUCTION],
            ],
            '15 (guards TWO-27302): only the environment at a store view checks the inherited key against it' => [
                $sandboxA, ['stores', 1, null, 'production'], [self::KEY_A, self::PRODUCTION],
                $sandboxA + ['stores' => [1 => ['mode' => 'production']]], [self::KEY_A, self::PRODUCTION],
            ],
            '7, 15: only the environment at a store view checks the key it inherits from its website' => [
                $sandboxA + $websiteB, ['stores', 1, null, 'sandbox'], [self::KEY_B, self::SANDBOX],
                $sandboxA + $websiteB + ['stores' => [1 => ['mode' => 'sandbox']]], [self::KEY_B, self::SANDBOX],
            ],
        ];
    }

    #[DataProvider('saves')]
    public function testASaveVerifiesAndStoresTheKeyForItsEnvironment(
        array $saved,
        array $submitted,
        array $expectedCheck,
        array $expectedStored,
        array $expectedNextCall
    ): void {
        $description = (string)$this->dataName();
        $this->wire($saved, null);

        $this->saveSection(...$submitted);
        $check = $this->sent;
        $this->sent = [];
        $this->adapter->execute(self::ORDER_ENDPOINT, ['gross_amount' => '100.00'], 'POST', 1);

        $this->assertCount(1, $check, "$description: one verification leaves");
        $this->assertSent($check[0], $expectedCheck[0], $expectedCheck[1], self::VERIFY_ENDPOINT, "$description, verification");
        $this->assertSame(
            self::readable($expectedStored),
            self::readable($this->config->rows()),
            "$description: saved config afterwards"
        );
        $this->assertCount(1, $this->sent, "$description: one order call leaves");
        $this->assertSent($this->sent[0], $expectedNextCall[0], $expectedNextCall[1], self::ORDER_ENDPOINT, "$description, next call");
    }

    /**
     * saved config | merchant action | key sent | host | key that must never be sent
     *
     * Actions, with the scope names the admin passes ('default', 'websites', 'stores'):
     * - ['button', scope, id, key, mode?]: the Verify API key button, posting the
     *   Environment dropdown's value when given (no mode: the dropdown was not found).
     * - ['env-switch', scope, id, mode]: switching the Environment dropdown with no
     *   key typed, which checks the saved key in the environment chosen.
     * - ['saved', scope, id]: the admin check of the key saved or inherited there.
     * - ['store-call', store id, current store]: a storefront or order call. A
     *   null store id reads the current store (null in the admin, which reads Default).
     */
    public static function scenarios(): array
    {
        $sandboxA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'sandbox']];
        $productionA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'production']];
        $storeOneC = ['stores' => [1 => ['api_key' => self::KEY_C, 'mode' => 'production']]];
        $websiteB = ['websites' => [1 => ['api_key' => self::KEY_B, 'mode' => 'production']]];

        return [
            '1: first key, Verify button on a fresh install' => [
                [], ['button', 'default', 0, self::KEY_K], self::KEY_K, self::SANDBOX, null,
            ],
            '3: changing the key in sandbox, Verify button' => [
                $sandboxA, ['button', 'default', 0, self::KEY_B], self::KEY_B, self::SANDBOX, self::KEY_A,
            ],
            '3: changing the key in production, Verify button' => [
                $productionA, ['button', 'default', 0, self::KEY_B], self::KEY_B, self::PRODUCTION, self::KEY_A,
            ],
            '4 (TWO-27303): the Verify button checks against the environment chosen, before it is saved' => [
                $sandboxA, ['button', 'default', 0, self::KEY_P, 'production'], self::KEY_P, self::PRODUCTION, self::KEY_A,
            ],
            '4 (TWO-27303): switching production to sandbox, Verify button' => [
                $productionA, ['button', 'default', 0, self::KEY_S, 'sandbox'], self::KEY_S, self::SANDBOX, self::KEY_A,
            ],
            '4 (TWO-27303): environment unchanged, Verify button' => [
                $sandboxA, ['button', 'default', 0, self::KEY_B, 'sandbox'], self::KEY_B, self::SANDBOX, self::KEY_A,
            ],
            '4 (TWO-27303): an unknown environment falls back to the saved one' => [
                $sandboxA, ['button', 'default', 0, self::KEY_B, 'development'], self::KEY_B, self::SANDBOX, self::KEY_A,
            ],
            '4 (TWO-27303): switching environment with no key typed checks the saved key there' => [
                $sandboxA, ['env-switch', 'default', 0, 'production'], self::KEY_A, self::PRODUCTION, null,
            ],
            '4 (TWO-27303): switching environment at a website checks the key it inherits' => [
                $sandboxA, ['env-switch', 'websites', 1, 'production'], self::KEY_A, self::PRODUCTION, null,
            ],
            '7 (TWO-27303): switching environment at a store view checks its website\'s key' => [
                $sandboxA + $websiteB, ['env-switch', 'stores', 1, 'sandbox'], self::KEY_B, self::SANDBOX, self::KEY_A,
            ],
            '7 (TWO-27303): Verify button at a store view uses the environment chosen over its website\'s' => [
                $sandboxA + $websiteB, ['button', 'stores', 1, self::KEY_K, 'sandbox'], self::KEY_K, self::SANDBOX, self::KEY_B,
            ],
            '8: key saved only at website scope, used for its store' => [
                ['websites' => [1 => ['api_key' => self::KEY_W, 'mode' => 'production']]],
                ['store-call', 1, null], self::KEY_W, self::PRODUCTION, null,
            ],
            '8: key saved only at store view scope, used for that store' => [
                ['stores' => [1 => ['api_key' => self::KEY_V, 'mode' => 'production']]],
                ['store-call', 1, null], self::KEY_V, self::PRODUCTION, null,
            ],
            '9: Verify button at website scope while Default has a different key' => [
                $sandboxA, ['button', 'websites', 1, self::KEY_K], self::KEY_K, self::SANDBOX, self::KEY_A,
            ],
            '7: Verify button at a store view uses the environment saved for its website' => [
                $sandboxA + $websiteB, ['button', 'stores', 1, self::KEY_K], self::KEY_K, self::PRODUCTION, self::KEY_B,
            ],
            '13: checking the key at a website that overrides nothing' => [
                $sandboxA, ['saved', 'websites', 1], self::KEY_A, self::SANDBOX, null,
            ],
            '14: checking the inherited key at a website that overrides only the environment' => [
                $sandboxA + ['websites' => [1 => ['mode' => 'production']]],
                ['saved', 'websites', 1], self::KEY_A, self::PRODUCTION, null,
            ],
            'extra: a call with no store id on store 1\'s storefront reads store 1' => [
                $sandboxA + $storeOneC, ['store-call', null, 1], self::KEY_C, self::PRODUCTION, self::KEY_A,
            ],
            'extra: a call with no store id in the admin reads Default' => [
                $sandboxA + $storeOneC, ['store-call', null, null], self::KEY_A, self::SANDBOX, self::KEY_C,
            ],
        ];
    }

    #[DataProvider('scenarios')]
    public function testTheMerchantsKeyReachesTheHostForTheirEnvironment(
        array $saved,
        array $action,
        string $expectedKey,
        string $expectedHost,
        ?string $neverSent
    ): void {
        $description = (string)$this->dataName();
        $this->wire($saved, $action[0] === 'store-call' ? $action[2] : null);

        match ($action[0]) {
            'button' => $this->pressVerify($action[1], $action[2], $action[3], $action[4] ?? null),
            'env-switch' => $this->pressVerify($action[1], $action[2], '', $action[3], true),
            'saved' => $this->apiKeyStatus->refresh(...AdminScope::fromScope($action[1], $action[2])),
            'store-call' => $this->adapter->execute(self::ORDER_ENDPOINT, ['gross_amount' => '100.00'], 'POST', $action[1]),
        };

        $this->assertCount(1, $this->sent, "$description: one request leaves");
        $endpoint = $action[0] === 'store-call' ? self::ORDER_ENDPOINT : self::VERIFY_ENDPOINT;
        $this->assertSent($this->sent[0], $expectedKey, $expectedHost, $endpoint, $description);
        if ($neverSent !== null) {
            $this->assertNotContains($neverSent, $this->sent[0]['headers'], "$description: $neverSent never sent");
            $this->assertStringNotContainsString($neverSent, $this->sent[0]['url'], "$description: $neverSent never sent");
        }
    }

    /**
     * saved config | store id => [key sent, host] for that store's own order call
     */
    public static function perStoreScenarios(): array
    {
        $sandboxA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'sandbox']];
        $productionA = ['default' => ['api_key' => self::KEY_A, 'mode' => 'production']];

        return [
            '5: key A at Default, key B at website 1' => [
                $sandboxA + ['websites' => [1 => ['api_key' => self::KEY_B]]],
                [1 => [self::KEY_B, self::SANDBOX], 2 => [self::KEY_B, self::SANDBOX], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '6: key A at Default, key C at store view 1' => [
                $sandboxA + ['stores' => [1 => ['api_key' => self::KEY_C, 'mode' => 'production']]],
                [1 => [self::KEY_C, self::PRODUCTION], 2 => [self::KEY_A, self::SANDBOX], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '7: key A at Default, key B at website 1, key C at store view 1' => [
                $sandboxA + ['websites' => [1 => ['api_key' => self::KEY_B]], 'stores' => [1 => ['api_key' => self::KEY_C]]],
                [1 => [self::KEY_C, self::SANDBOX], 2 => [self::KEY_B, self::SANDBOX], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '10, 12: Default sandbox with key A, website 1 production with key B' => [
                $sandboxA + ['websites' => [1 => ['api_key' => self::KEY_B, 'mode' => 'production']]],
                [1 => [self::KEY_B, self::PRODUCTION], 2 => [self::KEY_B, self::PRODUCTION], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '10: Default production with key A, website 1 sandbox with key B' => [
                $productionA + ['websites' => [1 => ['api_key' => self::KEY_B, 'mode' => 'sandbox']]],
                [1 => [self::KEY_B, self::SANDBOX], 2 => [self::KEY_B, self::SANDBOX], 3 => [self::KEY_A, self::PRODUCTION]],
            ],
            '13: website 1 overrides nothing' => [
                $sandboxA + ['websites' => [1 => []]],
                [1 => [self::KEY_A, self::SANDBOX], 2 => [self::KEY_A, self::SANDBOX], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '14: website 1 overrides only the environment' => [
                $sandboxA + ['websites' => [1 => ['mode' => 'production']]],
                [1 => [self::KEY_A, self::PRODUCTION], 2 => [self::KEY_A, self::PRODUCTION], 3 => [self::KEY_A, self::SANDBOX]],
            ],
            '15: store view 1 overrides only the environment, so it sends the inherited Default key to the production host' => [
                $sandboxA + ['stores' => [1 => ['mode' => 'production']]],
                [1 => [self::KEY_A, self::PRODUCTION], 2 => [self::KEY_A, self::SANDBOX], 3 => [self::KEY_A, self::SANDBOX]],
            ],
        ];
    }

    /** Every store's call is made before comparing, so a failure reports all three stores. */
    #[DataProvider('perStoreScenarios')]
    public function testEachStoreSendsItsOwnKeyToItsOwnEnvironment(array $saved, array $expected): void
    {
        $actual = [];
        foreach (array_keys($expected) as $storeId) {
            $this->wire($saved, null);
            $this->adapter->execute(self::ORDER_ENDPOINT, ['gross_amount' => '100.00'], 'POST', $storeId);
            $actual[$storeId] = array_map(static function (array $request): array {
                $url = parse_url($request['url']);
                return [$request['headers']['X-API-Key'] ?? null, $url['scheme'] . '://' . $url['host'], $url['path']];
            }, $this->sent);
        }

        $this->assertSame(
            array_map(static fn (array $call): array => [[$call[0], $call[1], self::ORDER_ENDPOINT]], $expected),
            $actual,
            $this->dataName() . ': store id => [[key, host, endpoint]] of each request'
        );
    }

    /**
     * @param array{method: string, url: string, headers: array<string, string>} $request
     */
    private function assertSent(
        array $request,
        string $expectedKey,
        string $expectedHost,
        string $expectedEndpoint,
        string $description
    ): void {
        $url = parse_url($request['url']);
        $this->assertSame($expectedKey, $request['headers']['X-API-Key'] ?? null, "$description: X-API-Key");
        $this->assertSame($expectedHost, $url['scheme'] . '://' . $url['host'], "$description: host");
        $this->assertSame($expectedEndpoint, $url['path'], "$description: endpoint");
    }

    /**
     * The section save as Magento runs it: a field with "Use Default" ticked
     * is deleted, the others are saved, deletes first, then saves in form
     * order. Each field sees the whole group's submission, and a field is
     * written or deleted only where its backend model allows it.
     */
    private function saveSection(string $scope, int $scopeId, ?string $key, ?string $mode): void
    {
        $fields = ['mode' => $mode, 'api_key' => $key];
        $common = [
            'groups' => ['general' => ['fields' => array_map(
                static fn ($value) => $value === null ? ['inherit' => 1] : ['value' => $value],
                $fields
            )]],
            'group_id' => 'general',
            'fieldset_data' => array_filter($fields, static fn ($value) => $value !== null),
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_code' => $scope === 'default' ? null : $scopeId,
        ];
        $models = [
            'mode' => new Mode(
                $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
                $this->config,
                $this->createMock(TypeListInterface::class),
                $this->createMock(MessageManager::class),
                $this->credentialsSave,
                null,
                null,
                $common + ['path' => 'payment/two_payment/mode', 'value' => $mode]
            ),
            'api_key' => new ApiKey(
                $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
                $this->config,
                $this->createMock(TypeListInterface::class),
                $this->encryptor(),
                $this->credentialsSave,
                null,
                null,
                $common + ['path' => 'payment/two_payment/api_key', 'value' => $key]
            ),
        ];

        foreach ($fields as $field => $value) {
            if ($value === null) {
                $models[$field]->beforeDelete();
                $this->config->delete($scope, $scopeId, $models[$field]->getPath());
            }
        }
        foreach ($fields as $field => $value) {
            if ($value !== null) {
                $models[$field]->beforeSave();
                if ($models[$field]->isSaveAllowed()) {
                    $this->config->set($scope, $scopeId, $models[$field]->getPath(), $models[$field]->getValue());
                }
            }
        }
    }

    private function pressVerify(string $scope, int $scopeId, string $key, ?string $mode, bool $stored = false): void
    {
        $params = ['api_key' => $key, 'scope' => $scope, 'scopeId' => $scopeId, 'mode' => $mode, 'stored' => $stored ? '1' : null];
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($name, $default = null) => $params[$name] ?? $default
        );
        $context = $this->createMock(ActionContext::class);
        $context->method('getRequest')->willReturn($request);

        (new VerifyApiKey(
            $context,
            new JsonFactory(),
            $this->apiKeyStatus,
            $this->statusMessage,
            $this->repository,
            $this->credentialsSave
        ))->execute();
    }

    /**
     * The real services over saved config, with the module's shipped
     * etc/config.xml defaults beneath it, and a transport that records each
     * request and answers as the API does for an accepted key.
     */
    private function wire(array $saved, ?int $currentStoreId): void
    {
        $this->sent = [];
        $this->config = new ScopedConfigStore(self::storedRows($saved), self::shippedDefaults(), $currentStoreId);

        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPaths')->willReturn(['Two_Gateway' => self::moduleRoot()]);
        $brand = new DescriptorBackedBrandRegistry(new ActiveBrandResolver(new Loader($registrar)));

        $repository = $this->repository = new Repository(
            $this->config,
            $this->encryptor(),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(TaxCalculation::class),
            $brand,
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class),
            $this->createMock(LogRepository::class)
        );
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturnCallback(fn () => $this->recordingCurl());
        $this->adapter = new Adapter(
            $repository,
            $brand,
            $curlFactory,
            $this->createMock(LogRepository::class),
            new NullApiTranslator()
        );

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $this->apiKeyStatus = new ApiKeyStatus(
            $this->adapter,
            $repository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class),
            new FlagManager()
        );
        $this->statusMessage = new ApiKeyStatusMessage($brand);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($id) => new class (ScopedConfigStore::STORE_WEBSITE[(int)$id]) {
                public function __construct(private readonly int $websiteId)
                {
                }

                public function getWebsiteId(): int
                {
                    return $this->websiteId;
                }
            }
        );
        $this->credentialsSave = new CredentialsSave(
            $this->apiKeyStatus,
            $this->statusMessage,
            $this->createMock(MessageManager::class),
            $this->config,
            $this->encryptor(),
            $storeManager
        );
    }

    /** @return Curl the transport: records each request, answers 200 with a merchant */
    private function recordingCurl(): Curl
    {
        $headers = [];
        $record = function (string $method, string $url) use (&$headers): void {
            $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        };
        $curl = $this->createMock(Curl::class);
        $curl->method('addHeader')->willReturnCallback(function ($name, $value) use (&$headers): void {
            $headers[$name] = $value;
        });
        $curl->method('get')->willReturnCallback(fn ($url) => $record('GET', $url));
        $curl->method('post')->willReturnCallback(fn ($url) => $record('POST', $url));
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturn([]);
        $curl->method('getBody')->willReturn('{"id":"merchant-1"}');

        return $curl;
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

    /** The test's saved settings as config rows. Keys are stored encrypted, as the admin saves them. */
    private static function storedRows(array $saved): array
    {
        $toRows = static function (array $fields): array {
            $rows = [];
            foreach ($fields as $field => $value) {
                $rows['payment/two_payment/' . $field] = $field === 'api_key' ? 'encrypted:' . $value : $value;
            }
            return $rows;
        };

        $rows = isset($saved['default']) ? ['default' => $toRows($saved['default'])] : [];
        foreach (['websites', 'stores'] as $scope) {
            foreach ($saved[$scope] ?? [] as $id => $fields) {
                $rows[$scope][$id] = $toRows($fields);
            }
        }

        return $rows;
    }

    /**
     * Config rows, or the test's settings, in one comparable shape: field =>
     * value per scope, keys decrypted, empty scopes dropped, keys sorted.
     */
    private static function readable(array $rows): array
    {
        $fields = static function (array $row): array {
            $out = [];
            foreach ($row as $path => $value) {
                $field = str_replace('payment/two_payment/', '', $path);
                $out[$field] = $field === 'api_key' && strpos((string)$value, 'encrypted:') === 0
                    ? substr((string)$value, 10)
                    : $value;
            }
            ksort($out);
            return $out;
        };

        $readable = [];
        if (!empty($rows['default'])) {
            $readable['default'] = $fields($rows['default']);
        }
        foreach (['websites', 'stores'] as $scope) {
            foreach ($rows[$scope] ?? [] as $id => $row) {
                if (!empty($row)) {
                    $readable[$scope][$id] = $fields($row);
                }
            }
            if (isset($readable[$scope])) {
                ksort($readable[$scope]);
            }
        }

        return $readable;
    }

    /** @return array<string, string> the module's etc/config.xml payment defaults, by config path */
    private static function shippedDefaults(): array
    {
        $xml = simplexml_load_file(self::moduleRoot() . '/etc/config.xml');
        $defaults = [];
        foreach ($xml->xpath('/config/default/payment/two_payment/*') as $node) {
            $defaults['payment/two_payment/' . $node->getName()] = trim((string)$node);
        }

        return $defaults;
    }

    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
