<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\HealthChecklist;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * TWO-25386, and ABN-518 for the checkout-visibility row.
 */
class HealthChecklistTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    /** @var RecordProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $recordProvider;

    /** @var SupportedCountriesProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $supportedCountriesProvider;

    /** @var MinimumOrderProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $minimumOrderProvider;

    /** @var MerchantMinimumResolver|\PHPUnit\Framework\MockObject\MockObject */
    private $merchantMinimumResolver;

    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /** @var HealthChecklist */
    private $block;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->recordProvider->method('status')
            ->willReturn([
                'fetched_at' => time() - 60,
                'absent_on_read_at' => null,
                'stood_in_at' => null,
                'scheduled_at' => null,
            ]);

        $this->supportedCountriesProvider = $this->createMock(SupportedCountriesProvider::class);
        $this->minimumOrderProvider = $this->createMock(MinimumOrderProvider::class);
        $this->merchantMinimumResolver = $this->createMock(MerchantMinimumResolver::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $this->request->method('getParam')->willReturn('');

        $this->block = new HealthChecklistTestable();
        $this->setBlockDependencies();
    }

    private function setBlockDependencies(): void
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');
        $brandRegistry->method('getProviderFullName')->willReturn('Acme Pay Ltd');

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('GBP');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->block->setDependencies(
            $this->configRepository,
            $this->apiKeyStatus,
            $this->recordProvider,
            $this->supportedCountriesProvider,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $brandRegistry,
            $storeManager,
            $this->scopeConfig,
            $this->request
        );
    }

    /**
     * @param array<string, int|null> $status
     * @dataProvider refreshStates
     */
    public function testTheMerchantProfileRowReportsTheRefresh(
        array $status,
        bool $expectedOk,
        string $expectedFragment,
        string $description
    ): void {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        // The panel must read the identity it is rendering, not another environment's stamp.
        $this->recordProvider->expects($this->once())->method('status')
            ->with('sandbox', 'key-a')
            ->willReturn($status);
        $this->setBlockDependencies();
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('getApiKey')->willReturn('key-a');

        $row = $this->block->getChecklistRows()[3];

        $this->assertSame('Merchant profile', $row['label'], $description);
        $this->assertSame($expectedOk, $row['ok'], $description);
        $this->assertStringContainsString($expectedFragment, $row['value'], $description);
    }

    /**
     * @return array<string, array{0: array<string, int|null>, 1: bool, 2: string, 3: string}>
     */
    public static function refreshStates(): array
    {
        // Ages, not instants — a mark older than a cron interval is a signal.
        $recent = time() - 60;
        $tick = RecordProvider::CRON_INTERVAL;
        $stopped = time() - RecordProvider::MAX_AGE - 2 * $tick - 1;

        return [
            'refreshed' => [
                ['fetched_at' => $recent, 'absent_on_read_at' => null, 'stood_in_at' => null, 'scheduled_at' => null],
                true,
                'Refreshed @' . $recent,
                'a refreshed profile shows when',
            ],
            'never refreshed' => [
                ['fetched_at' => null, 'absent_on_read_at' => null, 'stood_in_at' => null, 'scheduled_at' => null],
                false,
                'Never refreshed',
                'no stamp yet is not ok',
            ],
            'absent on read, unclaimed for longer than a cron run' => [
                [
                    'fetched_at' => null,
                    'absent_on_read_at' => time() - 2 * $tick - 1,
                    'stood_in_at' => null,
                    'scheduled_at' => null,
                ],
                false,
                'hourly refresh appears not to be running',
                'a read miss the cron never cleared is reported',
            ],
            'absent on read, since answered by a later fetch' => [
                [
                    'fetched_at' => $recent,
                    'absent_on_read_at' => time() - 2 * $tick - 1,
                    'stood_in_at' => null,
                    'scheduled_at' => null,
                ],
                true,
                'Refreshed @' . $recent,
                'a stamp newer than the mark means the miss has been answered',
            ],
            'absent on read, within this cron interval' => [
                ['fetched_at' => $recent, 'absent_on_read_at' => time(), 'stood_in_at' => null, 'scheduled_at' => null],
                true,
                'Refreshed @' . $recent,
                'a read miss the cron has not had a run to clear is the ordinary first read',
            ],
            'a read stood in for the cron, and the cron never cleared it' => [
                [
                    'fetched_at' => $recent,
                    'absent_on_read_at' => null,
                    'stood_in_at' => time() - 2 * $tick - 1,
                    'scheduled_at' => null,
                ],
                false,
                'hourly refresh appears not to be running',
                'a stand-in outliving a scheduled tick says the schedule is dead, however fresh the record',
            ],
            'a read stood in within this cron interval' => [
                [
                    'fetched_at' => $recent,
                    'absent_on_read_at' => null,
                    'stood_in_at' => time() - 10,
                    'scheduled_at' => null,
                ],
                true,
                'Refreshed @' . $recent,
                'a stand-in the cron has not had a tick to clear settles nothing',
            ],
            'a stamp the schedule should have replaced, with no mark at all' => [
                ['fetched_at' => $stopped, 'absent_on_read_at' => null, 'stood_in_at' => null, 'scheduled_at' => null],
                false,
                'hourly refresh appears not to be running',
                'a store with no traffic never stands in, so the stamp has to answer it',
            ],
            'the cron runs but its fetches keep failing' => [
                [
                    'fetched_at' => $stopped,
                    'absent_on_read_at' => null,
                    'stood_in_at' => null,
                    'scheduled_at' => time() - 60,
                ],
                true,
                'Refreshed',
                'a cron that runs and cannot reach the API is not a cron that is not running',
            ],
            'the cron itself has stopped running' => [
                [
                    'fetched_at' => time() - 60,
                    'absent_on_read_at' => null,
                    'stood_in_at' => null,
                    'scheduled_at' => time() - 2 * $tick - 1,
                ],
                false,
                'hourly refresh appears not to be running',
                'the run stamp going stale is the direct signal',
            ],
            'a stamp the schedule is due to replace' => [
                [
                    'fetched_at' => time() - RecordProvider::MAX_AGE - 1,
                    'absent_on_read_at' => null,
                    'stood_in_at' => null,
                    'scheduled_at' => null,
                ],
                true,
                'Refreshed',
                'a record merely due a refresh is not a dead schedule',
            ],
        ];
    }

    /**
     * ABN-518.
     *
     * @dataProvider checkoutVisibilityStates
     */
    public function testTheCheckoutVisibilityRowNamesTheActiveReason(
        bool $active,
        string $apiKeyStatus,
        ?string $surchargeType,
        string $countryState,
        ?array $platformMinimum,
        ?array $merchantMinimum,
        bool $coreRestrictedToNoCountry,
        bool $expectedOk,
        string $expectedFragment,
        string $description
    ): void {
        $this->configRepository->method('isActive')->willReturn($active);
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => $apiKeyStatus]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        if ($surchargeType !== null) {
            $this->configRepository->method('getSurchargeType')->willReturn($surchargeType);
        } else {
            $this->configRepository->method('getSurchargeType')
                ->willThrowException(new LocalizedException(new \Magento\Framework\Phrase('unavailable')));
        }
        $this->supportedCountriesProvider->method('getState')->willReturn($countryState);
        $this->minimumOrderProvider->method('getMinimum')->with(null)->willReturn($platformMinimum);
        // The resolver is parameterised by method code and base currency; a row
        // that passed either wrongly would report another method's floor.
        $this->merchantMinimumResolver->method('resolve')
            ->with('two_payment', 'GBP', $platformMinimum, null)
            ->willReturn($merchantMinimum);
        $this->scopeConfig->method('isSetFlag')->willReturn($coreRestrictedToNoCountry);
        $this->scopeConfig->method('getValue')->willReturn('');

        $row = $this->block->getChecklistRows()[4];

        $this->assertSame('Payment method at checkout', $row['label'], $description);
        $this->assertSame($expectedOk, $row['ok'], $description);
        $this->assertStringContainsString($expectedFragment, $row['value'], $description);
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: string|null, 3: string,
     *     4: array<string,mixed>|null, 5: array<string,mixed>|null, 6: bool, 7: bool, 8: string, 9: string}>
     */
    public static function checkoutVisibilityStates(): array
    {
        $eur = ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'];
        $gbp = ['amount' => 1000.0, 'currency' => 'GBP', 'basis' => 'gross'];
        $eurHigher = ['amount' => 500.0, 'currency' => 'EUR', 'basis' => 'net'];
        $unrestricted = SupportedCountriesProvider::STATE_UNRESTRICTED;

        return [
            'disabled' => [
                false, ApiKeyStatus::OK, 'none', $unrestricted, null, null, false, false,
                'Check Enable payment method',
                'the switched-off method names the field that switches it on',
            ],
            'no key saved' => [
                true, ApiKeyStatus::NOT_CONFIGURED, 'none', $unrestricted, null, null, false, false,
                'no API key is saved',
                'an unconfigured install is not a rejected key',
            ],
            'key rejected' => [
                true, ApiKeyStatus::INVALID_KEY, 'none', $unrestricted, null, null, false, false,
                'the API key was rejected',
                'a definitive rejection names both key and environment',
            ],
            'key unverifiable, service down' => [
                true, ApiKeyStatus::SERVICE_ERROR, 'none', $unrestricted, null, null, false, true,
                'Shown at checkout',
                'ABN-533: a transient verdict falls through to the cached record and withholds nothing',
            ],
            'key unverifiable, unreachable' => [
                true, ApiKeyStatus::UNREACHABLE, 'none', $unrestricted, null, null, false, true,
                'Shown at checkout',
                'the same for a store that cannot reach us at all',
            ],
            'stored surcharge method unknown' => [
                true, ApiKeyStatus::OK, null, $unrestricted, null, null, false, false,
                'Check Surcharge method',
                'a corrupt stored surcharge type withholds and names its own field',
            ],
            'account allows no buyer countries' => [
                true, ApiKeyStatus::OK, 'none', SupportedCountriesProvider::STATE_EMPTY, null, null, false, false,
                'no buyer countries are currently enabled for your account',
                'an empty allowlist hides the method for every buyer, which no local field explains',
            ],
            'core allowlist restricted to nothing' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, null, null, true, false,
                'Check Allowed countries',
                'the two country gates are separate settings and name themselves separately',
            ],
            'nothing withholding it' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, null, null, false, true,
                'Shown at checkout',
                'nothing withholding it reads as shown',
            ],
            'platform minimum only' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, $eur, null, false, true,
                'hidden for baskets below 250.00 EUR (excluding tax)',
                'the basket-dependent gate is named as a constraint, not as the current state',
            ],
            'merchant minimum only' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, null, $gbp, false, true,
                'hidden for baskets below 1000.00 GBP (including tax)',
                'the merchant own floor binds even with no platform floor',
            ],
            'both minimums bind' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, $eur, $gbp, false, true,
                '250.00 EUR (excluding tax) or 1000.00 GBP (including tax)',
                'two floors in different currencies cannot be reduced to one, so both are named',
            ],
            'a configured surcharge is a currency constraint' => [
                true, ApiKeyStatus::OK, 'percentage', $unrestricted, null, null, false, true,
                'hidden for baskets in a currency the buyer surcharge cannot be priced in',
                'whether the fee can be priced depends on the basket currency, so it is a constraint',
            ],
            'both minimums in the same currency' => [
                true, ApiKeyStatus::OK, 'none', $unrestricted, $eur, $eurHigher, false, true,
                'hidden for baskets below 500.00 EUR (excluding tax)',
                'same currency and basis is one floor — naming both would state a bar that never binds',
            ],
            'the account allowlist could not be read' => [
                true, ApiKeyStatus::OK, 'none', SupportedCountriesProvider::STATE_MALFORMED, null, null, false, false,
                'could not be read',
                'an unreadable list is not a deliberate account restriction',
            ],
        ];
    }

    /**
     * ABN-518: the row has to judge the same store the checkout gate would,
     * not the default scope, on a website- or store-view-scoped page.
     *
     * @dataProvider scopeParams
     */
    public function testTheRowJudgesTheScopeThePageIsOpenAt(
        string $storeParam,
        string $websiteParam,
        bool $storeResolves,
        ?int $expectedStoreId,
        string $description
    ): void {
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($name) => $name === 'store' ? $storeParam : ($name === 'website' ? $websiteParam : '')
        );

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(7);
        $store->method('getBaseCurrencyCode')->willReturn('GBP');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        if ($storeResolves) {
            $storeManager->method('getStore')->willReturn($store);
            $storeManager->method('getWebsite')->willReturn(
                new class ($store) {
                    private $store;

                    public function __construct($store)
                    {
                        $this->store = $store;
                    }

                    public function getDefaultStore()
                    {
                        return $this->store;
                    }
                }
            );
        } else {
            $storeManager->method('getStore')
                ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
            $storeManager->method('getWebsite')
                ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
        }

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');
        $this->block->setDependencies(
            $this->configRepository,
            $this->apiKeyStatus,
            $this->recordProvider,
            $this->supportedCountriesProvider,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $brandRegistry,
            $storeManager,
            $this->scopeConfig,
            $request
        );
        $this->configRepository->expects($this->once())->method('isActive')->with($expectedStoreId)
            ->willReturn(false);
        // The checkout row's own verdict read judges the page's scope; the
        // panel's separate "API key" row is unscoped.
        $scopesAsked = [];
        $this->apiKeyStatus->method('getStatus')
            ->willReturnCallback(function ($storeId = null) use (&$scopesAsked) {
                $scopesAsked[] = $storeId;
                return ['status' => ApiKeyStatus::OK];
            });
        $this->configRepository->method('getMode')->willReturn('sandbox');

        $row = $this->block->getChecklistRows()[4];

        // The scope assertion is `isActive()`'s own `with($expectedStoreId)`;
        // this proves the read reached the row rather than being swallowed.
        $this->assertStringContainsString('Check Enable payment method', $row['value'], $description);
        $this->assertContains($expectedStoreId, $scopesAsked, $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: int|null, 4: string}>
     */
    public static function scopeParams(): array
    {
        return [
            'default scope' => ['', '', true, null, 'no scope param reads the default scope'],
            'store view' => ['7', '', true, 7, 'a store-view page judges that store'],
            'website' => ['', '3', true, 7, "a website page judges the website's default store"],
            'stale store param' => ['999', '', false, null, 'an unresolvable scope degrades, never throws'],
            'stale website param' => ['', '999', false, null, 'and the same for a website'],
        ];
    }

    /**
     * ABN-518: a platform floor that has never been fetched is unknown, not
     * absent, and a bare "shown at checkout" would read as no floor at all.
     */
    public function testAProfileThatHasNeverResolvedNamesTheUnknownFloor(): void
    {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->recordProvider->method('status')->willReturn([
            'fetched_at' => null,
            'absent_on_read_at' => null,
            'stood_in_at' => null,
            'scheduled_at' => null,
        ]);
        $this->setBlockDependencies();
        $this->configRepository->method('isActive')->willReturn(true);
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('getSurchargeType')->willReturn('none');
        $this->supportedCountriesProvider->method('getState')
            ->willReturn(SupportedCountriesProvider::STATE_UNRESTRICTED);
        $this->minimumOrderProvider->method('getMinimum')->willReturn(null);
        $this->merchantMinimumResolver->method('resolve')
            ->willReturn(['amount' => 1000.0, 'currency' => 'GBP', 'basis' => 'gross']);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $row = $this->block->getChecklistRows()[4];

        $this->assertTrue($row['ok']);
        $this->assertStringContainsString('minimum order value not known until your profile refreshes', $row['value']);
        // A local admin value is known even when the platform floor is not.
        $this->assertStringContainsString('1000.00 GBP (including tax)', $row['value']);
    }

    public function testAllHealthyRows(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $rows = $this->block->getChecklistRows();

        $this->assertTrue($rows[0]['ok']);
        $this->assertSame('PRODUCTION', $rows[1]['value']);
        $this->assertTrue($rows[2]['ok']);
        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }

    /**
     * ABN-533 narrowed which categories withhold the payment method from the
     * BUYER. The admin's own verdict is unchanged: anything short of a
     * verified key still reads "Not verified" here.
     *
     * @dataProvider apiKeyRowStates
     */
    public function testTheApiKeyRowReportsOnlyAVerifiedKeyAsOk(
        string $status,
        bool $expectedOk,
        string $description
    ): void {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => $status]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $row = $this->block->getChecklistRows()[0];

        $this->assertSame($expectedOk, $row['ok'], $description);
        $this->assertSame($expectedOk ? 'Verified' : 'Not verified', $row['value'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function apiKeyRowStates(): array
    {
        return [
            'ok' => [ApiKeyStatus::OK, true, 'a verified key is the only ok state'],
            'invalid key' => [ApiKeyStatus::INVALID_KEY, false, 'a rejected key is not verified'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, false,
                'an outage still leaves the key unconfirmed on the admin panel'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, false,
                'the buyer keeps the method, the admin is still told it is unconfirmed'],
            'other error' => [ApiKeyStatus::ERROR, false, 'no confirmation, not ok'],
            'malformed response' => [ApiKeyStatus::MALFORMED_RESPONSE, false, 'no confirmation, not ok'],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, false, 'nothing to verify'],
        ];
    }

    public function testSslDisabledRowIsNotOk(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $rows = $this->block->getChecklistRows();

        $this->assertFalse($rows[2]['ok']);
    }

    public function testProductionWithSslDisabledWarns(): void
    {
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertTrue($this->block->isProductionWithSslDisabled());
    }

    public function testSandboxWithSslDisabledDoesNotWarn(): void
    {
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }
}

/**
 * Constructor-free subclass — the heavy Field base constructor needs a
 * full Magento admin Context, which this exercises no need for.
 */
class HealthChecklistTestable extends HealthChecklist
{
    public function __construct()
    {
    }

    public function setDependencies(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        RecordProvider $recordProvider,
        SupportedCountriesProvider $supportedCountriesProvider,
        MinimumOrderProvider $minimumOrderProvider,
        MerchantMinimumResolver $merchantMinimumResolver,
        BrandRegistryInterface $brandRegistry,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\RequestInterface $request
    ): void {
        $ref = new \ReflectionClass(HealthChecklist::class);

        foreach ([
            'supportedCountriesProvider' => $supportedCountriesProvider,
            'minimumOrderProvider' => $minimumOrderProvider,
            'merchantMinimumResolver' => $merchantMinimumResolver,
            'brandRegistry' => $brandRegistry,
        ] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($this, $value);
        }
        $this->_storeManager = $storeManager;
        $this->_scopeConfig = $scopeConfig;
        $this->request = $request;

        $recordProp = $ref->getProperty('recordProvider');
        $recordProp->setAccessible(true);
        $recordProp->setValue($this, $recordProvider);

        $configProp = $ref->getProperty('configRepository');
        $configProp->setAccessible(true);
        $configProp->setValue($this, $configRepository);

        $apiKeyProp = $ref->getProperty('apiKeyStatus');
        $apiKeyProp->setAccessible(true);
        $apiKeyProp->setValue($this, $apiKeyStatus);
    }

    /** @var mixed */
    private $request;

    /** The stub base takes its request from a Context this exercises without. */
    public function getRequest()
    {
        return $this->request;
    }

    /** The real one needs the locale from Context; render the epoch instead. */
    protected function formatTimestamp(int $timestamp): string
    {
        return '@' . $timestamp;
    }
}
