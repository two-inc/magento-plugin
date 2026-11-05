<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Webapi\SoleTrader;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\RateLimiter;

/**
 * TWO-27057: sole-trader tokens are minted only for the session's own active
 * quote, on a store where the method is enabled, and only when the caller
 * names that quote.
 */
class SoleTraderCartBindingTest extends TestCase
{
    private const MASKED = 'maskedGuestCart0123456789abcdef';

    public static function carts(): array
    {
        $own = ['id' => 42, 'active' => 1, 'items' => 2, 'method' => true, 'masked' => self::MASKED];
        $noMask = static fn (): \Throwable => new NoSuchEntityException(__('No mask for a customer quote.'));

        return [
            'a guest names their quote by its masked id' => [$own, self::MASKED, true],
            'a customer or a checkout sending the plain id names their quote' => [$own, '42', true],
            'a quote with no mask is still named by its own id' => [['masked' => $noMask] + $own, '42', true],
            'another cart\'s masked id' => [$own, 'maskedSomeoneElse0123456789abcd', false],
            'another cart\'s plain id' => [$own, '43', false],
            'a plain id that only starts like the quote\'s' => [$own, '42 ', false],
            'no cart named' => [$own, '', false],
            'a quote with no mask and a foreign id' => [['masked' => $noMask] + $own, 'foreign', false],
            'no quote in the session' => [['id' => null] + $own, '', false],
            'an inactive quote' => [['active' => 0] + $own, self::MASKED, false],
            'an empty quote' => [['items' => 0] + $own, self::MASKED, false],
            'the method is disabled on the store' => [['method' => false] + $own, self::MASKED, false],
        ];
    }

    #[DataProvider('carts')]
    public function testTokensAreMintedOnlyForTheSessionsOwnCart(array $cart, string $cartId, bool $mints): void
    {
        $upstreamCalls = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('withIdempotencyKey')->willReturnSelf();
        $adapter->method('execute')->willReturnCallback(function () use (&$upstreamCalls) {
            $upstreamCalls++;
            return ['two-delegated-authority-token' => 'token-' . $upstreamCalls];
        });

        $description = $this->dataName();
        try {
            $result = $this->soleTrader($adapter, $cart)->getTokens($cartId);
            $this->assertTrue($mints, "$description: should have been refused");
            $this->assertSame([['delegation_token' => 'token-1', 'autofill_token' => 'token-2']], $result, $description);
        } catch (WebapiException $e) {
            $this->assertFalse($mints, "$description: refused: " . $e->getMessage());
            $this->assertSame(403, $e->getHttpCode(), "$description: status");
            $this->assertSame(0, $upstreamCalls, "$description: nothing asked of Two");
        }
    }

    private function soleTrader(Adapter $adapter, array $cart): SoleTrader
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($cart['id']);
        $quote->method('getIsActive')->willReturn($cart['active']);
        $quote->method('getItemsCount')->willReturn($cart['items']);
        $quote->method('getStoreId')->willReturn(1);
        $session = new CheckoutSession();
        $session->setData('quote', $quote);
        $session->setData('session_id', 'sess-a');

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isActive')->willReturn($cart['method']);

        $masks = $this->createMock(QuoteIdToMaskedQuoteIdInterface::class);
        if (is_callable($cart['masked'])) {
            $masks->method('execute')->willThrowException(($cart['masked'])());
        } else {
            $masks->method('execute')->willReturn($cart['masked']);
        }

        return new SoleTrader(
            $adapter,
            $this->createMock(SupportedCompanyTypes::class),
            $this->createMock(RateLimiter::class),
            $session,
            $config,
            $masks
        );
    }
}
