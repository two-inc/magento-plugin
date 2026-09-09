<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\HealthChecklist;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * TWO-25386: the admin "Health checklist" panel. Five checks: API key,
 * environment, SSL verification, merchant profile refresh, and (ABN-518)
 * whether the payment method reaches checkout.
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

        $this->block = new HealthChecklistTestable();
        $this->setBlockDependencies();
    }

    private function setBlockDependencies(): void
    {
        $this->block->setDependencies(
            $this->configRepository,
            $this->apiKeyStatus,
            $this->recordProvider,
            $this->supportedCountriesProvider,
            $this->minimumOrderProvider
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
        bool $surchargeTypeKnown,
        ?array $allowedCountries,
        ?array $minimum,
        bool $expectedOk,
        string $expectedFragment,
        string $description
    ): void {
        $this->configRepository->method('isActive')->willReturn($active);
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => $apiKeyStatus]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        if ($surchargeTypeKnown) {
            $this->configRepository->method('getSurchargeType')->willReturn('none');
        } else {
            $this->configRepository->method('getSurchargeType')
                ->willThrowException(new LocalizedException(new \Magento\Framework\Phrase('unavailable')));
        }
        $this->supportedCountriesProvider->method('getAllowedCountries')->willReturn($allowedCountries);
        $this->minimumOrderProvider->method('getMinimum')->willReturn($minimum);

        $row = $this->block->getChecklistRows()[4];

        $this->assertSame('Payment method at checkout', $row['label'], $description);
        $this->assertSame($expectedOk, $row['ok'], $description);
        $this->assertStringContainsString($expectedFragment, $row['value'], $description);
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: bool, 3: array<int,string>|null,
     *     4: array<string,mixed>|null, 5: bool, 6: string, 7: string}>
     */
    public static function checkoutVisibilityStates(): array
    {
        $eur = ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'];

        return [
            'disabled' => [
                false, ApiKeyStatus::OK, true, null, null, false,
                'Check Enable payment method',
                'the switched-off method names the field that switches it on',
            ],
            'no key saved' => [
                true, ApiKeyStatus::NOT_CONFIGURED, true, null, null, false,
                'no API key is saved',
                'an unconfigured install is not a rejected key',
            ],
            'key rejected' => [
                true, ApiKeyStatus::INVALID_KEY, true, null, null, false,
                'the API key was rejected',
                'a definitive rejection names both key and environment',
            ],
            'key unverifiable, service down' => [
                true, ApiKeyStatus::SERVICE_ERROR, true, null, null, false,
                'could not be verified just now',
                'a transient verdict must not be reported as the method being withheld (ABN-533)',
            ],
            'key unverifiable, unreachable' => [
                true, ApiKeyStatus::UNREACHABLE, true, null, null, false,
                'could not be verified just now',
                'the same for a store that cannot reach us at all',
            ],
            'stored surcharge method unknown' => [
                true, ApiKeyStatus::OK, false, null, null, false,
                'Check Surcharge method',
                'a corrupt stored surcharge type withholds and names its own field',
            ],
            'account allows no buyer countries' => [
                true, ApiKeyStatus::OK, true, [], null, false,
                'allows no buyer countries',
                'an empty allowlist hides the method for every buyer, which no local field explains',
            ],
            'unrestricted account, no minimum' => [
                true, ApiKeyStatus::OK, true, null, null, true,
                'Shown at checkout',
                'nothing withholding it reads as shown',
            ],
            'allowlisted account' => [
                true, ApiKeyStatus::OK, true, ['NO', 'GB'], null, true,
                'Shown at checkout',
                'a populated allowlist is not a reason to withhold',
            ],
            'minimum order value in force' => [
                true, ApiKeyStatus::OK, true, null, $eur, true,
                'hidden for baskets below 250.00 EUR (net)',
                'the basket-dependent gate is named as a constraint, not as the current state',
            ],
        ];
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
        ?SupportedCountriesProvider $supportedCountriesProvider = null,
        ?MinimumOrderProvider $minimumOrderProvider = null
    ): void {
        $ref = new \ReflectionClass(HealthChecklist::class);

        if ($supportedCountriesProvider !== null) {
            $countriesProp = $ref->getProperty('supportedCountriesProvider');
            $countriesProp->setAccessible(true);
            $countriesProp->setValue($this, $supportedCountriesProvider);
        }

        if ($minimumOrderProvider !== null) {
            $minimumProp = $ref->getProperty('minimumOrderProvider');
            $minimumProp->setAccessible(true);
            $minimumProp->setValue($this, $minimumOrderProvider);
        }

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

    /** The real one needs the locale from Context; render the epoch instead. */
    protected function formatTimestamp(int $timestamp): string
    {
        return '@' . $timestamp;
    }
}
