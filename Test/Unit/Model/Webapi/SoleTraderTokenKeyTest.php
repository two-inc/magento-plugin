<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Webapi\SoleTrader;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\RateLimiter;

/**
 * A token is a credential for one buyer, so its Idempotency-Key is never
 * shared between buyers (TWO-26658).
 */
class SoleTraderTokenKeyTest extends TestCase
{
    /**
     * @param array{0: int|null, 1: string} $first
     * @param array{0: int|null, 1: string} $second
     */
    #[DataProvider('buyers')]
    public function testATokenKeyIsNeverSharedBetweenBuyers(
        array $first,
        array $second,
        bool $same,
        string $description
    ): void {
        $a = $this->keysFor(...$first);
        $b = $this->keysFor(...$second);

        $this->assertMatchesRegularExpression('/^delegation_token_\d*_[0-9a-f]{12}$/', $a[0], $description);
        $this->assertMatchesRegularExpression('/^autofill_token_\d*_[0-9a-f]{12}$/', $a[1], $description);
        if ($same) {
            $this->assertSame($a, $b, $description);
        } else {
            $this->assertSame([], array_intersect($a, $b), $description);
        }
    }

    /**
     * @return array<string, array{0: array, 1: array, 2: bool, 3: string}>
     */
    public static function buyers(): array
    {
        return [
            'one buyer, one session' => [[42, 'sess-a'], [42, 'sess-a'], true, 'a retry in one session reuses its keys'],
            'two buyers, no quote id' => [[null, 'sess-a'], [null, 'sess-b'], false, 'buyers whose quote has no id still get keys of their own'],
            'one quote, a new session' => [[42, 'sess-a'], [42, 'sess-b'], false, 'a new session mints under new keys'],
        ];
    }

    /**
     * @return list<string> the keys of the delegation and autofill requests
     */
    private function keysFor(?int $quoteId, string $sessionId): array
    {
        $keys = [];
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('withIdempotencyKey')->willReturnCallback(
            function (string $key) use (&$keys, &$adapter): Adapter {
                $keys[] = $key;
                return $adapter;
            }
        );
        $adapter->method('execute')->willReturn([]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $session = new CheckoutSession();
        $session->setData('quote', $quote);
        $session->setData('session_id', $sessionId);

        (new SoleTrader(
            $adapter,
            $this->createMock(SupportedCompanyTypes::class),
            $this->createMock(RateLimiter::class),
            $session
        ))->getTokens('cart-1');

        return $keys;
    }
}
