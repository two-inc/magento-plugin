<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Webapi\CompanyLookup;
use Two\Gateway\Model\Webapi\OrderIntent;
use Two\Gateway\Model\Webapi\SoleTrader;
use Two\Gateway\Model\Webapi\Surcharges;
use Two\Gateway\Model\Webapi\TermSelection;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\TermSurchargePreview;
use Two\Gateway\Service\RateLimiter;

/**
 * Every route this module registers is anonymous, and each either spends a
 * merchant credential upstream or mutates the quote. The ceiling has to be
 * on all of them, not only the ones added last.
 */
class AnonymousRouteRateLimitsTest extends TestCase
{
    /**
     * Given a caller already over its ceiling; When it calls any anonymous
     * route; Then the call is refused before the route does any work.
     *
     * @dataProvider anonymousRouteMethods
     */
    public function testEveryAnonymousRouteRefusesACallerOverItsCeiling(
        string $route,
        string $description
    ): void {
        $this->expectException(WebapiException::class);
        $this->expectExceptionMessage('Too many requests. Please wait a moment and try again.');

        $this->invoke($route, $description);
    }

    /**
     * One entry per `<resource ref="anonymous"/>` route in etc/webapi.xml.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function anonymousRouteMethods(): array
    {
        return [
            'POST /V1/two/get-tokens' => ['get-tokens', 'sole-trader token mint'],
            'GET /V1/two/supported-company-types' => ['supported-company-types', 'company-type lookup'],
            'POST /V1/two/select-term' => ['select-term', 'term selection'],
            'POST /V1/two/company-search' => ['company-search', 'registry search'],
            'POST /V1/two/company' => ['company', 'registry detail'],
            'POST /V1/two/order-intent' => ['order-intent', 'order intent'],
            'GET /V1/two/surcharges' => ['surcharges', 'surcharge read'],
        ];
    }

    /**
     * The provider's route keys are the only place the route set is written
     * down twice — this pins it against webapi.xml itself.
     */
    public function testTheProviderCoversEveryAnonymousRouteRegistered(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/webapi.xml');
        $registered = [];
        foreach ($xml->route as $route) {
            if (!isset($route->resources->resource['ref']) ||
                (string)$route->resources->resource['ref'] !== 'anonymous') {
                continue;
            }
            $url = preg_replace('#/:[^/]+#', '', (string)$route['url']);
            $registered[] = (string)$route['method'] . ' ' . $url;
        }

        sort($registered);
        $covered = array_keys(self::anonymousRouteMethods());
        sort($covered);

        $this->assertSame($registered, $covered);
    }

    private function invoke(string $route, string $description): void
    {
        $limiter = $this->exhaustedLimiter();

        switch ($route) {
            case 'get-tokens':
                $this->soleTrader($limiter)->getTokens('cart-1');
                return;
            case 'supported-company-types':
                $this->soleTrader($limiter)->getSupportedCompanyTypes('NO');
                return;
            case 'select-term':
                $this->termSelection($limiter)->selectTerm('cart-1', 30);
                return;
            case 'company-search':
                $this->companyLookup($limiter)->search('no', 'acme');
                return;
            case 'company':
                $this->companyLookup($limiter)->get('lookup-1');
                return;
            case 'order-intent':
                (new OrderIntent(
                    $this->createMock(Adapter::class),
                    $this->createMock(ApiKeyStatus::class),
                    $limiter,
                    $this->createMock(LogRepository::class),
                    $this->createMock(CheckoutSession::class),
                    $this->createMock(BuyerCountryResolver::class),
                    $this->createMock(SupportedCountriesProvider::class)
                ))->place('{}');
                return;
            case 'surcharges':
                $this->surcharges($limiter)->get('cart-1');
                return;
        }

        $this->fail(sprintf('no invocation wired for %s (%s)', $route, $description));
    }

    private function companyLookup(RateLimiter $limiter): CompanyLookup
    {
        return new CompanyLookup(
            $this->createMock(Adapter::class),
            $this->createMock(ApiKeyStatus::class),
            $limiter,
            $this->createMock(LogRepository::class),
            $this->createMock(CheckoutSession::class)
        );
    }

    private function soleTrader(RateLimiter $limiter): SoleTrader
    {
        return new SoleTrader(
            $this->createMock(Adapter::class),
            $this->createMock(SupportedCompanyTypes::class),
            $limiter,
            $this->createMock(CheckoutSession::class)
        );
    }

    private function termSelection(RateLimiter $limiter): TermSelection
    {
        return new TermSelection(
            $this->createMock(CheckoutSession::class),
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(TermSurchargePreview::class),
            $this->createMock(LogRepository::class),
            $limiter
        );
    }

    private function surcharges(RateLimiter $limiter): Surcharges
    {
        return new Surcharges(
            $this->createMock(CheckoutSession::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(TermSurchargePreview::class),
            $this->createMock(LogRepository::class),
            $limiter
        );
    }

    private function exhaustedLimiter(): RateLimiter
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('100000');
        $request = new HttpRequest();
        $request->setTestEnvironment(['REMOTE_ADDR' => '198.51.100.7']);

        return new RateLimiter(
            $cache,
            $request,
            $this->createMock(ConfigRepository::class),
            $this->createMock(LogRepository::class)
        );
    }
}
