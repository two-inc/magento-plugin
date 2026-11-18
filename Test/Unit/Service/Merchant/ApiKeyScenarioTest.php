<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Backend\App\Action\Context as ActionContext;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
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
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Controller\Adminhtml\Config\VerifyApiKey;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
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
 * action, and checks the request as it leaves for the network. The real
 * Adapter, default translator, config Repository, ApiKeyStatus, save rule
 * (CredentialsSave with the Mode and ApiKey backend models) and Verify
 * button controller run; only config storage and the HTTP transport are
 * fakes. Stores 1 and 2 are in website 1, store 3 in website 2.
 */
class ApiKeyScenarioTest extends TestCase
{
    private const PRODUCTION = 'https://api.two.inc';
    private const SANDBOX = 'https://api.sandbox.two.inc';
    private const VERIFY_ENDPOINT = '/v1/merchant/verify_api_key';
    private const ORDER_ENDPOINT = '/v1/order';

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

    /**
     * saved config | merchant action | key sent | host | key that must never be sent
     *
     * Actions, with the scope names the admin passes ('default', 'websites',
     * 'stores'):
     * - ['save', scope, id, key, mode]: the admin saves the section. A key of
     *   '' leaves the field blank, which keeps the stored key; null ticks
     *   "Use Default" (or "Use Website") on that field.
     * - ['button', scope, id, key]: the Verify API key button.
     * - ['saved', scope, id]: the admin check of the key saved or inherited there.
     * - ['store-call', store id, current store]: a storefront or order call. A
     *   null store id reads the current store (null in the admin, which reads Default).
     */
    public static function scenarios(): array
    {
        $sandboxA = ['default' => ['api_key' => 'key-A-1234567890abcdef', 'mode' => 'sandbox']];
        $productionA = ['default' => ['api_key' => 'key-A-1234567890abcdef', 'mode' => 'production']];
        $storeOneC = ['stores' => [1 => ['api_key' => 'key-C-1234567890abcdef', 'mode' => 'production']]];

        // phpcs:disable Generic.Files.LineLength
        return [
            '1: first key, sandbox chosen' => [
                [], ['save', 'default', 0, 'key-K-1234567890abcdef', 'sandbox'], 'key-K-1234567890abcdef', self::SANDBOX, null,
            ],
            '1: first key, Verify button on a fresh install' => [
                [], ['button', 'default', 0, 'key-K-1234567890abcdef'], 'key-K-1234567890abcdef', self::SANDBOX, null,
            ],
            '2: first key, production chosen' => [
                [], ['save', 'default', 0, 'key-K-1234567890abcdef', 'production'], 'key-K-1234567890abcdef', self::PRODUCTION, null,
            ],
            '2: first key at website scope, production chosen' => [
                [], ['save', 'websites', 1, 'key-K-1234567890abcdef', 'production'], 'key-K-1234567890abcdef', self::PRODUCTION, null,
            ],
            '3: changing the key in sandbox' => [
                $sandboxA, ['save', 'default', 0, 'key-B-1234567890abcdef', 'sandbox'], 'key-B-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '3: changing the key in sandbox, Verify button' => [
                $sandboxA, ['button', 'default', 0, 'key-B-1234567890abcdef'], 'key-B-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '3: changing the key in production' => [
                $productionA, ['save', 'default', 0, 'key-B-1234567890abcdef', 'production'], 'key-B-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            '3: changing the key in production, Verify button' => [
                $productionA, ['button', 'default', 0, 'key-B-1234567890abcdef'], 'key-B-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            '4: switching sandbox to production with a production key' => [
                $sandboxA, ['save', 'default', 0, 'key-P-1234567890abcdef', 'production'], 'key-P-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            '4: switching production to sandbox with a sandbox key' => [
                $productionA, ['save', 'default', 0, 'key-S-1234567890abcdef', 'sandbox'], 'key-S-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '4: switching to production at website scope' => [
                ['websites' => [1 => ['api_key' => 'key-A-1234567890abcdef', 'mode' => 'sandbox']]],
                ['save', 'websites', 1, 'key-P-1234567890abcdef', 'production'], 'key-P-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            '4: switching environment without changing the key checks the saved key against the new one' => [
                $sandboxA, ['save', 'default', 0, '', 'production'], 'key-A-1234567890abcdef', self::PRODUCTION, null,
            ],
            '4 (records current behaviour, TWO-27303): the Verify button checks against the saved environment, not the one chosen' => [
                $sandboxA, ['button', 'default', 0, 'key-P-1234567890abcdef'], 'key-P-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '8: first key saved only at website scope, nothing at Default' => [
                [], ['save', 'websites', 1, 'key-W-1234567890abcdef', null], 'key-W-1234567890abcdef', self::SANDBOX, null,
            ],
            '8: first key saved only at store view scope, nothing at Default' => [
                [], ['save', 'stores', 1, 'key-V-1234567890abcdef', null], 'key-V-1234567890abcdef', self::SANDBOX, null,
            ],
            '8: key saved only at website scope, used for its store' => [
                ['websites' => [1 => ['api_key' => 'key-W-1234567890abcdef', 'mode' => 'production']]],
                ['store-call', 1, null], 'key-W-1234567890abcdef', self::PRODUCTION, null,
            ],
            '8: key saved only at store view scope, used for that store' => [
                ['stores' => [1 => ['api_key' => 'key-V-1234567890abcdef', 'mode' => 'production']]],
                ['store-call', 1, null], 'key-V-1234567890abcdef', self::PRODUCTION, null,
            ],
            '9: saving a new key at website scope while Default has a different key' => [
                $sandboxA, ['save', 'websites', 1, 'key-K-1234567890abcdef', null], 'key-K-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '9: Verify button at website scope while Default has a different key' => [
                $sandboxA, ['button', 'websites', 1, 'key-K-1234567890abcdef'], 'key-K-1234567890abcdef', self::SANDBOX, 'key-A-1234567890abcdef',
            ],
            '11: saving at website scope with production chosen while Default is on sandbox' => [
                $sandboxA, ['save', 'websites', 1, 'key-K-1234567890abcdef', 'production'], 'key-K-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            '13: checking the key at a website that overrides nothing' => [
                $sandboxA, ['saved', 'websites', 1], 'key-A-1234567890abcdef', self::SANDBOX, null,
            ],
            '14: checking the inherited key at a website that overrides only the environment' => [
                $sandboxA + ['websites' => [1 => ['mode' => 'production']]],
                ['saved', 'websites', 1], 'key-A-1234567890abcdef', self::PRODUCTION, null,
            ],
            '14: saving only the environment at a website checks the inherited key against it' => [
                $sandboxA, ['save', 'websites', 1, null, 'production'], 'key-A-1234567890abcdef', self::PRODUCTION, null,
            ],
            '15: saving only the environment at a store view checks the inherited key against it' => [
                $sandboxA, ['save', 'stores', 1, null, 'production'], 'key-A-1234567890abcdef', self::PRODUCTION, null,
            ],
            'extra: a call with no store id on store 1\'s storefront reads store 1' => [
                $sandboxA + $storeOneC, ['store-call', null, 1], 'key-C-1234567890abcdef', self::PRODUCTION, 'key-A-1234567890abcdef',
            ],
            'extra: a call with no store id in the admin reads Default' => [
                $sandboxA + $storeOneC, ['store-call', null, null], 'key-A-1234567890abcdef', self::SANDBOX, 'key-C-1234567890abcdef',
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    #[DataProvider('scenarios')]
    public function testTheMerchantsKeyReachesTheHostForTheirEnvironment(
        array $saved,
        array $action,
        string $expectedKey,
        string $expectedHost,
        ?string $neverSent
    ): void {
        $sent = $this->send($saved, $action);
        $description = (string)$this->dataName();

        $this->assertCount(1, $sent, "$description: one request leaves");
        $this->assertSent(
            $sent[0],
            $expectedKey,
            $expectedHost,
            $action[0] === 'store-call' ? self::ORDER_ENDPOINT : self::VERIFY_ENDPOINT,
            $description
        );
        if ($neverSent !== null) {
            $this->assertNotContains($neverSent, $sent[0]['headers'], "$description: $neverSent never sent");
            $this->assertStringNotContainsString($neverSent, $sent[0]['url'], "$description: $neverSent never sent");
        }
    }

    /**
     * saved config | store id => [key sent, host] for that store's own call
     */
    public static function perStoreScenarios(): array
    {
        $sandboxA = ['default' => ['api_key' => 'key-A-1234567890abcdef', 'mode' => 'sandbox']];
        $productionA = ['default' => ['api_key' => 'key-A-1234567890abcdef', 'mode' => 'production']];

        // phpcs:disable Generic.Files.LineLength
        return [
            '5: key A at Default, key B at website 1' => [
                $sandboxA + ['websites' => [1 => ['api_key' => 'key-B-1234567890abcdef']]],
                [1 => ['key-B-1234567890abcdef', self::SANDBOX], 2 => ['key-B-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '6: key A at Default, key C at store view 1' => [
                $sandboxA + ['stores' => [1 => ['api_key' => 'key-C-1234567890abcdef', 'mode' => 'production']]],
                [1 => ['key-C-1234567890abcdef', self::PRODUCTION], 2 => ['key-A-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '7: key A at Default, key B at website 1, key C at store view 1' => [
                $sandboxA + ['websites' => [1 => ['api_key' => 'key-B-1234567890abcdef']], 'stores' => [1 => ['api_key' => 'key-C-1234567890abcdef']]],
                [1 => ['key-C-1234567890abcdef', self::SANDBOX], 2 => ['key-B-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '10, 12: Default sandbox with key A, website 1 production with key B' => [
                $sandboxA + ['websites' => [1 => ['api_key' => 'key-B-1234567890abcdef', 'mode' => 'production']]],
                [1 => ['key-B-1234567890abcdef', self::PRODUCTION], 2 => ['key-B-1234567890abcdef', self::PRODUCTION], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '10: Default production with key A, website 1 sandbox with key B' => [
                $productionA + ['websites' => [1 => ['api_key' => 'key-B-1234567890abcdef', 'mode' => 'sandbox']]],
                [1 => ['key-B-1234567890abcdef', self::SANDBOX], 2 => ['key-B-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::PRODUCTION]],
            ],
            '13: website 1 overrides nothing' => [
                $sandboxA + ['websites' => [1 => []]],
                [1 => ['key-A-1234567890abcdef', self::SANDBOX], 2 => ['key-A-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '14: website 1 overrides only the environment' => [
                $sandboxA + ['websites' => [1 => ['mode' => 'production']]],
                [1 => ['key-A-1234567890abcdef', self::PRODUCTION], 2 => ['key-A-1234567890abcdef', self::PRODUCTION], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
            '15 (records current behaviour, TWO-27302): store view 1 overrides only the environment, so it sends the Default key to the production host' => [
                $sandboxA + ['stores' => [1 => ['mode' => 'production']]],
                [1 => ['key-A-1234567890abcdef', self::PRODUCTION], 2 => ['key-A-1234567890abcdef', self::SANDBOX], 3 => ['key-A-1234567890abcdef', self::SANDBOX]],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    #[DataProvider('perStoreScenarios')]
    public function testEachStoreSendsItsOwnKeyToItsOwnEnvironment(array $saved, array $expected): void
    {
        foreach ($expected as $storeId => [$expectedKey, $expectedHost]) {
            $sent = $this->send($saved, ['store-call', $storeId, null]);
            $description = $this->dataName() . ", store $storeId";

            $this->assertCount(1, $sent, "$description: one request leaves");
            $this->assertSent($sent[0], $expectedKey, $expectedHost, self::ORDER_ENDPOINT, $description);
        }
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
     * Runs one merchant action against freshly wired services and returns
     * every request that left.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>}>
     */
    private function send(array $saved, array $action): array
    {
        $this->wire($saved, $action[0] === 'store-call' ? $action[2] : null);

        match ($action[0]) {
            'save' => $this->saveSection($action[1], $action[2], $action[3], $action[4]),
            'button' => $this->pressVerify($action[1], $action[2], $action[3]),
            'saved' => $this->apiKeyStatus->refresh(...AdminScope::fromScope($action[1], $action[2])),
            'store-call' => $this->adapter->execute(self::ORDER_ENDPOINT, ['gross_amount' => '100.00'], 'POST', $action[1]),
        };

        return $this->sent;
    }

    /**
     * The section save as Magento runs it: a field with "Use Default" ticked
     * is deleted, the others are saved, deletes first, then saves in form
     * order. Each field sees the whole group's submission.
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
            }
        }
        foreach ($fields as $field => $value) {
            if ($value !== null) {
                $models[$field]->beforeSave();
            }
        }
    }

    private function pressVerify(string $scope, int $scopeId, string $key): void
    {
        $params = ['api_key' => $key, 'scope' => $scope, 'scopeId' => $scopeId];
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($name, $default = null) => $params[$name] ?? $default
        );
        $context = $this->createMock(ActionContext::class);
        $context->method('getRequest')->willReturn($request);

        (new VerifyApiKey($context, new JsonFactory(), $this->apiKeyStatus, $this->statusMessage))->execute();
    }

    /**
     * The real services over saved config, with the module's shipped
     * etc/config.xml defaults under Default, and a transport that records
     * each request and answers as the API does for an accepted key.
     */
    private function wire(array $saved, ?int $currentStoreId): void
    {
        $this->sent = [];
        $this->config = new ScopedConfigStore(self::storedRows($saved), $currentStoreId);

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCode')->willReturn('two_payment');
        $brand->method('getProductName')->willReturn('Two');
        $brand->method('getCheckoutUrlTemplate')->willReturn(self::shippedCheckoutUrlTemplate());

        $repository = new Repository(
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

    /**
     * The test's saved settings as config rows, under the shipped defaults
     * Magento merges into Default. Keys are stored encrypted, as the admin saves them.
     */
    private static function storedRows(array $saved): array
    {
        $toRows = static function (array $fields): array {
            $rows = [];
            foreach ($fields as $field => $value) {
                $rows['payment/two_payment/' . $field] = $field === 'api_key' ? 'encrypted:' . $value : $value;
            }
            return $rows;
        };

        $rows = ['default' => $toRows($saved['default'] ?? []) + self::shippedDefaults()];
        foreach (['websites', 'stores'] as $scope) {
            foreach ($saved[$scope] ?? [] as $id => $fields) {
                $rows[$scope][$id] = $toRows($fields);
            }
        }

        return $rows;
    }

    /** @return array<string, string> the module's etc/config.xml payment defaults, by config path */
    private static function shippedDefaults(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/config.xml');
        $defaults = [];
        foreach ($xml->xpath('/config/default/payment/two_payment/*') as $node) {
            $defaults['payment/two_payment/' . $node->getName()] = trim((string)$node);
        }

        return $defaults;
    }

    private static function shippedCheckoutUrlTemplate(): string
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/brand.xml');

        return trim((string)($xml->xpath('//brand[@code="two_payment"]/checkout_url_template')[0] ?? ''));
    }
}
