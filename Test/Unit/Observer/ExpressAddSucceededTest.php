<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Controller\Express\Confirm;
use Two\Gateway\Observer\ExpressAddSucceeded;

/**
 * TWO-25800: the completion record is a SET keyed by the attempt's own token.
 *
 * A session is not one tab. Two express adds can complete before either is
 * confirmed, so the record has to hold both: one slot means neither buyer can
 * be told their item went in, though both items did.
 */
class ExpressAddSucceededTest extends TestCase
{
    /** @var mixed */
    private $stored = null;

    private function observer(string $returnUrl): array
    {
        $request = new class ($returnUrl) {
            public function __construct(private string $returnUrl)
            {
            }

            public function getParam($name)
            {
                return $name === 'return_url' ? $this->returnUrl : null;
            }
        };

        $event = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getData'])
            ->getMock();
        $event->method('getData')->willReturnCallback(
            static fn ($key = null) => $key === 'request' ? $request : null
        );

        $observerArg = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->addMethods(['getEvent'])
            ->getMock();
        $observerArg->method('getEvent')->willReturn($event);

        $session = $this->createMock(CheckoutSession::class);
        $session->method('getData')->willReturnCallback(fn () => $this->stored);
        $session->method('setData')->willReturnCallback(function ($key, $value) {
            $this->stored = $value;
        });

        return [new ExpressAddSucceeded($session), $observerArg];
    }

    private function complete(string $token): void
    {
        [$observer, $event] = $this->observer(
            'https://shop.test/' . Confirm::ROUTE . '?product=42&' . Confirm::TOKEN_PARAM . '=' . $token
        );
        $observer->execute($event);
    }

    protected function setUp(): void
    {
        $this->stored = null;
    }

    /** The first completion in a session starts the set. */
    public function testACompletionIsRecordedUnderItsOwnToken(): void
    {
        $this->complete('tok-abc');

        $this->assertSame(['tok-abc' => true], $this->stored);
    }

    /**
     * Two tabs, two completions, neither confirmed yet. A record holding one
     * completion at a time loses the earlier one, and its buyer is then turned
     * back with their item sitting in the basket.
     */
    public function testASecondTabsCompletionDoesNotEraseTheFirst(): void
    {
        $this->complete('tok-abc');
        $this->complete('tok-def');

        $this->assertSame(['tok-abc' => true, 'tok-def' => true], $this->stored);
    }

    /**
     * A tab closed between the add and the confirmation leaves its stamp
     * behind and nothing else removes it, so the set is bounded. The oldest go
     * first: a stamp is only wanted for the moment between the add completing
     * and core's redirect arriving.
     */
    public function testTheSetIsBoundedAndDropsTheOldestFirst(): void
    {
        for ($i = 0; $i < Confirm::MAX_STAMPS + 5; $i++) {
            $this->complete('tok-' . $i);
        }

        $this->assertCount(Confirm::MAX_STAMPS, $this->stored);
        $this->assertArrayNotHasKey('tok-0', $this->stored);
        $this->assertArrayHasKey('tok-' . (Confirm::MAX_STAMPS + 4), $this->stored);
    }

    /**
     * An ordinary Add to Cart on the same page carries no return URL of ours,
     * and must leave nothing a later express attempt could read as its own.
     */
    public function testAnOrdinaryAddLeavesNothingBehind(): void
    {
        [$observer, $event] = $this->observer('https://shop.test/checkout/cart/');
        $observer->execute($event);

        $this->assertNull($this->stored);
    }
}
