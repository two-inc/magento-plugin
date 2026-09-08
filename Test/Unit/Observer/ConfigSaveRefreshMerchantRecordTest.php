<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Observer\ConfigSaveRefreshMerchantRecord;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * The refresh that runs when a General section save changes the API key or
 * the environment: the identities the saved scope governs, on a budget
 * because it sits inside the admin's save request.
 */
class ConfigSaveRefreshMerchantRecordTest extends TestCase
{
    /** @var RecordRefresher|\PHPUnit\Framework\MockObject\MockObject */
    private $recordRefresher;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var array<int,array{mode: string, api_key: string, store_id: int|null}> */
    private const IDENTITY = [['mode' => 'sandbox', 'api_key' => 'key-a', 'store_id' => 7]];

    protected function setUp(): void
    {
        $this->recordRefresher = $this->createMock(RecordRefresher::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->with('nl_store')->willReturn($store);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(3);
        $this->storeManager->method('getWebsite')->with('base')->willReturn($website);
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private function dispatch(array $eventData): void
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getData'])
            ->getMock();
        $event->method('getData')->willReturnCallback(
            function ($key = null) use ($eventData) {
                return $eventData[$key] ?? null;
            }
        );
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->addMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        (new ConfigSaveRefreshMerchantRecord($this->recordRefresher, $this->storeManager, $this->logRepository))
            ->execute($observer);
    }

    /**
     * @param array<string,string> $eventData
     * @dataProvider savedScopes
     */
    public function testTheSavedScopeIsWhatIsRefreshed(
        array $eventData,
        string $expectedScope,
        int $expectedScopeId,
        string $description
    ): void {
        // The event carries scope CODES; the refresher decides what the saved scope governs.
        $asked = [];
        $this->recordRefresher->method('governedIdentities')->willReturnCallback(
            function (string $scope, int $scopeId) use (&$asked) {
                $asked[] = [$scope, $scopeId];
                return self::IDENTITY;
            }
        );
        $this->recordRefresher->expects($this->once())
            ->method('refreshWithin')
            ->with(self::IDENTITY, 15.0)
            ->willReturn(['records' => [['id' => 'abc']], 'skipped' => 0]);

        $this->dispatch($eventData);

        $this->assertSame([[$expectedScope, $expectedScopeId]], $asked, $description);
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: string, 2: int, 3: string}>
     */
    public static function savedScopes(): array
    {
        return [
            'store view' => [['store' => 'nl_store'], 'stores', 7, 'a store-view save'],
            'website' => [['website' => 'base'], 'websites', 3, 'a website save'],
            'default' => [[], 'default', 0, 'a default-scope save'],
        ];
    }

    /**
     * @param array<string,string> $eventData
     * @dataProvider nothingToRefresh
     */
    public function testNothingIsRefreshedWhenTheScopeGovernsNothing(
        array $eventData,
        ?string $failingLookup,
        ?\Exception $governedFailure,
        string $description
    ): void {
        // A gone scope, or one nothing reads through, is not the default scope.
        if ($failingLookup !== null) {
            $this->storeManager = $this->createMock(StoreManagerInterface::class);
            $this->storeManager->method($failingLookup)->willThrowException(new NoSuchEntityException(__('gone')));
        }
        if ($governedFailure !== null) {
            $this->recordRefresher->method('governedIdentities')->willThrowException($governedFailure);
        }
        $this->recordRefresher->expects($this->never())->method('refreshWithin');
        $logged = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function (string $type) use (&$logged) {
                $logged[] = $type;
            }
        );

        $this->dispatch($eventData);

        $this->assertCount(1, $logged, $description);
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: string|null, 2: \Exception|null, 3: string}>
     */
    public static function nothingToRefresh(): array
    {
        return [
            'store code gone' => [['store' => 'nl_store'], 'getStore', null, 'a deleted store view'],
            'website code gone' => [['website' => 'base'], 'getWebsite', null, 'a deleted website'],
            'nothing governed' => [
                ['website' => 'base'],
                null,
                new LocalizedException(__('every store view has its own key')),
                'a website whose key nobody inherits',
            ],
        ];
    }

    /**
     * @param array<int,string>|null $changedPaths null when the event carries none
     * @dataProvider changedPaths
     */
    public function testOnlyASaveChangingTheKeyOrEnvironmentRefreshes(
        ?array $changedPaths,
        bool $expectedRefresh,
        string $description
    ): void {
        // The section carries other fields, and a refresh can block the save for two 60s calls.
        $this->recordRefresher->method('governedIdentities')->willReturn(self::IDENTITY);
        $refreshes = 0;
        $this->recordRefresher->method('refreshWithin')->willReturnCallback(
            function () use (&$refreshes) {
                $refreshes++;
                return ['records' => [null], 'skipped' => 0];
            }
        );

        $this->dispatch($changedPaths === null ? [] : ['changed_paths' => $changedPaths]);

        $this->assertSame($expectedRefresh ? 1 : 0, $refreshes, $description);
    }

    /**
     * @return array<string, array{0: array<int,string>|null, 1: bool, 2: string}>
     */
    public static function changedPaths(): array
    {
        return [
            'api key' => [['payment/two_payment/api_key'], true, 'a key change refreshes'],
            'environment' => [['payment/two_payment/mode'], true, 'an environment change refreshes'],
            'both plus another' => [
                ['payment/two_payment/debug', 'payment/two_payment/mode'],
                true,
                'the credential change is found among other fields',
            ],
            'other field only' => [['payment/two_payment/debug'], false, 'an unrelated field does not refresh'],
            'slashless path' => [['api_key'], true, 'a path with no slash is matched whole'],
            'no-change save' => [[], false, 'Save Config with nothing changed does not refresh'],
            'event without changed_paths' => [null, true, 'an event shape that cannot say is taken as changed'],
        ];
    }

    public function testIdentitiesPastTheBudgetAreLoggedAndLeftToTheCron(): void
    {
        $this->recordRefresher->method('governedIdentities')->willReturn(self::IDENTITY);
        $this->recordRefresher->method('refreshWithin')->willReturn(['records' => [null], 'skipped' => 2]);
        $this->logRepository->expects($this->once())->method('addDebugLog')
            ->with($this->anything(), ['skipped' => 2]);

        $this->dispatch([]);
    }
}
