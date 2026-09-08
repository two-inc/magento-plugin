<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Controller\Adminhtml\Config\RefreshMerchantRecord;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * The Diagnostics "Refresh merchant profile" button's endpoint.
 */
class RefreshMerchantRecordTest extends TestCase
{
    /** @var RecordRefresher|\PHPUnit\Framework\MockObject\MockObject */
    private $recordRefresher;

    protected function setUp(): void
    {
        $this->recordRefresher = $this->createMock(RecordRefresher::class);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function invoke(array $params = []): array
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            function ($key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $controller = new RefreshMerchantRecord($context, new JsonFactory(), $this->recordRefresher);

        return (array)$controller->execute()->getData();
    }

    /**
     * @param int $identities how many the scope governs
     * @param array<int,array<string,mixed>|null> $records what the attempted ones returned
     */
    private function governsAndRefreshes(int $identities, array $records): void
    {
        $this->recordRefresher->method('governedIdentities')->willReturn(array_fill(
            0,
            $identities,
            ['mode' => 'sandbox', 'api_key' => 'key-a', 'store_id' => null]
        ));
        $this->recordRefresher->method('refreshWithin')
            ->willReturn(['records' => $records, 'skipped' => $identities - count($records)]);
    }

    /**
     * @dataProvider scopes
     */
    public function testThePostedScopeIsWhatIsRefreshed(
        array $params,
        string $expectedScope,
        int $expectedScopeId,
        string $description
    ): void {
        // Losing the scope would refresh the default scope and name a merchant the admin was not looking at.
        $identity = [['mode' => 'production', 'api_key' => 'key-a', 'store_id' => 7]];
        $this->recordRefresher->expects($this->once())
            ->method('governedIdentities')
            ->with($expectedScope, $expectedScopeId)
            ->willReturn($identity);
        $this->recordRefresher->expects($this->once())
            ->method('refreshWithin')
            ->with($identity, 20.0)
            ->willReturn(['records' => [['id' => 'abc-123']], 'skipped' => 0]);

        $this->assertTrue($this->invoke($params)['success'], $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string, 2: int, 3: string}>
     */
    public static function scopes(): array
    {
        return [
            'store view' => [['scope' => 'stores', 'scopeId' => 7], 'stores', 7, 'a store-scope press'],
            'website' => [['scope' => 'websites', 'scopeId' => 3], 'websites', 3, 'a website-scope press'],
            'default' => [[], 'default', 0, 'no scope posted is the default scope'],
        ];
    }

    /**
     * @param array<int,array<string,mixed>|null> $records one per attempted identity
     * @dataProvider outcomes
     */
    public function testTheOutcomeIsReportedInline(
        int $identities,
        array $records,
        bool $expectedSuccess,
        string $expectedFragment,
        string $expectedMerchant,
        string $description
    ): void {
        // The refresh is the only cache interaction; the message says which profiles did refresh.
        $this->governsAndRefreshes($identities, $records);

        $response = $this->invoke();

        $this->assertSame($expectedSuccess, $response['success'], $description);
        $this->assertStringContainsString($expectedFragment, (string)$response['message'], $description);
        $this->assertSame($expectedMerchant, (string)($response['merchant'] ?? ''), $description);
    }

    /**
     * @return array<string, array{0: int, 1: array<int,array<string,mixed>|null>, 2: bool, 3: string, 4: string, 5: string}>
     */
    public static function outcomes(): array
    {
        return [
            'refreshed' => [
                1,
                [['id' => 'abc-123', 'short_name' => 'acme']],
                true,
                'refreshed',
                'acme · abc-123',
                'a successful refetch names the merchant it refreshed',
            ],
            'refreshed without a short name' => [
                1,
                [['id' => 'abc-123']],
                true,
                'refreshed',
                'abc-123',
                'the id alone identifies the merchant when no short name is returned',
            ],
            'refreshed without id or short name' => [
                2,
                [['id' => 'abc-123'], ['legal_name' => 'Acme']],
                true,
                'refreshed',
                'abc-123',
                'a record naming nothing adds no empty item to the list',
            ],
            'unresolvable' => [
                1,
                [null],
                false,
                'still in use',
                '',
                'a failed refetch is reported as a failure, not as an empty success',
            ],
            'two profiles, both refreshed' => [
                2,
                [['id' => 'abc-123', 'short_name' => 'acme'], ['id' => 'def-456']],
                true,
                'refreshed',
                'acme · abc-123, def-456',
                'every governed identity is named',
            ],
            'three profiles, one failed' => [
                3,
                [['id' => 'abc-123', 'short_name' => 'acme'], null, ['id' => 'def-456']],
                false,
                'Refreshed 2 of 3 merchant profiles',
                'acme · abc-123, def-456',
                'partial failure counts profiles, and names the ones that did refresh',
            ],
            'out of time' => [
                4,
                [['id' => 'abc-123', 'short_name' => 'acme'], null],
                false,
                'Refreshed 1 of 4 merchant profiles before the request ran out of time',
                'acme · abc-123',
                'identities not attempted are reported as such, not as key failures',
            ],
        ];
    }

    /**
     * @dataProvider ungoverned
     */
    public function testAScopeGoverningNothingIsReportedWithItsReason(
        \Exception $failure,
        string $expectedFragment,
        string $description
    ): void {
        // Falling through to the default scope would report success for a merchant the admin was not looking at.
        $this->recordRefresher->method('governedIdentities')->willThrowException($failure);
        $this->recordRefresher->expects($this->never())->method('refreshWithin');

        $response = $this->invoke(['scope' => 'websites', 'scopeId' => 3]);

        $this->assertFalse($response['success'], $description);
        $this->assertStringContainsString($expectedFragment, (string)$response['message'], $description);
    }

    /**
     * @return array<string, array{0: \Exception, 1: string, 2: string}>
     */
    public static function ungoverned(): array
    {
        return [
            'scope gone' => [
                new NoSuchEntityException(__('No such entity with %1 = %2', ['website_id', 3])),
                'no longer exists',
                'a deleted website is its own message, not "no store view"',
            ],
            'nothing reads the key' => [
                new LocalizedException(__('Every store view in this website has its own API key.')),
                'its own API key',
                'the refresher\'s reason is relayed as-is',
            ],
        ];
    }
}
