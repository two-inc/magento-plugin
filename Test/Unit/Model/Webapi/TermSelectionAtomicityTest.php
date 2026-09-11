<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Webapi\TermSelection;
use Two\Gateway\Service\Order\TermSurchargePreview;
use Two\Gateway\Service\RateLimiter;

/**
 * The session term is what the surcharge is priced on and what placement
 * composes the order from, so a call that did not answer must not leave it
 * moved (ABN-550).
 */
class TermSelectionAtomicityTest extends TestCase
{
    /**
     * Given a select-term call that fails after the term is staged; When it
     * throws; Then the session holds the term it held before the call, and the
     * quote is repriced back only if it was already saved on the staged term.
     *
     * @dataProvider failurePoints
     */
    public function testAFailedCallLeavesThePreviousTermInTheSession(
        string $failAt,
        int $expectedCollects,
        int $expectedSaves,
        string $case
    ): void {
        $session = new CheckoutSession();
        $session->setTwoSelectedTerm(30);

        $quote = new class ($failAt) {
            public int $collectCalls = 0;

            public function __construct(private string $failAt)
            {
            }

            public function getStoreId(): int
            {
                return 1;
            }

            public function getId(): int
            {
                return 7;
            }

            public function collectTotals(): self
            {
                $this->collectCalls++;
                if ($this->failAt === 'collect') {
                    throw new RuntimeException('pricing upstream unavailable');
                }
                return $this;
            }
        };
        $session->setQuote($quote);

        $cartRepository = new class implements \Magento\Quote\Api\CartRepositoryInterface {
            public int $saveCalls = 0;

            public function save($quote): void
            {
                $this->saveCalls++;
            }
        };

        $cartTotalRepository = new class ($failAt) implements \Magento\Quote\Api\CartTotalRepositoryInterface {
            public function __construct(private string $failAt)
            {
            }

            public function get($cartId)
            {
                if ($this->failAt === 'totals') {
                    throw new RuntimeException('totals read failed');
                }
                return null;
            }
        };

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isBuyerTermAvailable')->willReturn(true);

        $subject = new TermSelection(
            $session,
            $cartRepository,
            $cartTotalRepository,
            $config,
            $this->createMock(TermSurchargePreview::class),
            $this->permissiveLimiter(),
            $this->createMock(LogRepository::class)
        );

        try {
            $subject->selectTerm('cart-1', 60);
            $this->fail('selectTerm was expected to throw for ' . $case);
        } catch (RuntimeException $error) {
            $this->assertSame(30, (int)$session->getTwoSelectedTerm(), $case);
            $this->assertSame($expectedCollects, $quote->collectCalls, $case);
            $this->assertSame($expectedSaves, $cartRepository->saveCalls, $case);
        }
    }

    public static function failurePoints(): array
    {
        return [
            ['collect', 1, 0, 'the repricing itself failed, so nothing was persisted to undo'],
            ['totals', 2, 2, 'the quote was already saved on the staged term'],
        ];
    }

    private function permissiveLimiter(): RateLimiter
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');
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
