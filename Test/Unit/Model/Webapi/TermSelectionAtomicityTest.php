<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
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
     * quote is repriced on that term whenever it may already have been saved on
     * the staged one.
     *
     * @dataProvider failurePoints
     */
    public function testAFailedCallLeavesThePreviousTermInTheSession(
        string $failAt,
        array $expectedTermsPriced,
        int $expectedSaves,
        string $case
    ): void {
        $session = new CheckoutSession();
        $session->setTwoSelectedTerm(30);
        $quote = $this->quoteDouble($failAt, $session);
        $session->setQuote($quote);
        $cartRepository = $this->cartRepository($failAt);

        $subject = $this->subject($session, $cartRepository, $this->totalsRepository($failAt), $this->logDouble());

        try {
            $subject->selectTerm('cart-1', 60);
            $this->fail('selectTerm was expected to throw for ' . $case);
        } catch (RuntimeException $error) {
            $this->assertSame(30, (int)$session->getTwoSelectedTerm(), $case);
            $this->assertSame($expectedTermsPriced, $quote->termsPriced, $case);
            $this->assertSame($expectedSaves, $cartRepository->saveCalls, $case);
        }
    }

    public static function failurePoints(): array
    {
        return [
            ['collect', [60], 0, 'the repricing itself failed, so nothing was persisted to undo'],
            ['save', [60, 30], 2, 'a save that threw may still have persisted the staged term'],
            ['totals', [60, 30], 2, 'the quote was already saved on the staged term'],
        ];
    }

    /**
     * Given the repricing back fails too; When selectTerm throws; Then the
     * session is left on the term the saved quote prices, so placement refuses
     * the disagreement rather than charging one term's fee against another, and
     * the failure is logged rather than swallowed.
     */
    public function testARestoreThatAlsoFailsLeavesTheSessionOnTheSavedTerm(): void
    {
        $session = new CheckoutSession();
        $session->setTwoSelectedTerm(30);
        $quote = $this->quoteDouble('restore', $session);
        $session->setQuote($quote);
        $log = $this->logDouble();

        $subject = $this->subject(
            $session,
            $this->cartRepository('restore'),
            $this->totalsRepository('restore'),
            $log
        );

        try {
            $subject->selectTerm('cart-1', 60);
            $this->fail('selectTerm was expected to throw');
        } catch (RuntimeException $error) {
            $this->assertSame(60, (int)$session->getTwoSelectedTerm());
            $this->assertSame([60, 30], $quote->termsPriced);
            $this->assertSame(['TermSelectionRollback'], $log->errors);
        }
    }

    private function subject(
        CheckoutSession $session,
        CartRepositoryInterface $cartRepository,
        CartTotalRepositoryInterface $totalsRepository,
        LogRepository $log
    ): TermSelection {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isBuyerTermAvailable')->willReturn(true);

        return new TermSelection(
            $session,
            $cartRepository,
            $totalsRepository,
            $config,
            $this->createMock(TermSurchargePreview::class),
            $this->permissiveLimiter(),
            $log
        );
    }

    /** Records the session term each repricing saw — what the collector prices on. */
    private function quoteDouble(string $failAt, CheckoutSession $session): object
    {
        return new class ($failAt, $session) {
            /** @var int[] */
            public array $termsPriced = [];

            public function __construct(private string $failAt, private CheckoutSession $session)
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
                $this->termsPriced[] = (int)$this->session->getTwoSelectedTerm();
                if ($this->failAt === 'collect') {
                    throw new RuntimeException('pricing upstream unavailable');
                }
                if ($this->failAt === 'restore' && count($this->termsPriced) > 1) {
                    throw new RuntimeException('repricing back failed too');
                }
                return $this;
            }
        };
    }

    private function cartRepository(string $failAt): CartRepositoryInterface
    {
        return new class ($failAt) implements CartRepositoryInterface {
            public int $saveCalls = 0;

            public function __construct(private string $failAt)
            {
            }

            public function save($quote): void
            {
                $this->saveCalls++;
                if ($this->failAt === 'save' && $this->saveCalls === 1) {
                    throw new RuntimeException('quote save failed');
                }
            }
        };
    }

    private function totalsRepository(string $failAt): CartTotalRepositoryInterface
    {
        return new class ($failAt) implements CartTotalRepositoryInterface {
            public function __construct(private string $failAt)
            {
            }

            public function get($cartId)
            {
                if ($this->failAt === 'totals' || $this->failAt === 'restore') {
                    throw new RuntimeException('totals read failed');
                }
                return null;
            }
        };
    }

    private function logDouble(): LogRepository
    {
        return new class implements LogRepository {
            /** @var string[] */
            public array $errors = [];

            public function addErrorLog(string $type, $data)
            {
                $this->errors[] = $type;
            }

            public function addDebugLog(string $type, $data)
            {
            }

            public function addLog(string $type, $data)
            {
            }
        };
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
