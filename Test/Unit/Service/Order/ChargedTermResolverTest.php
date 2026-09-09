<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Order\ChargedTermResolver;

class ChargedTermResolverTest extends TestCase
{
    /**
     * Given a session selection and a configured default; when the charged
     * term is resolved; then the buyer's own pick wins.
     *
     * @dataProvider terms
     */
    public function testResolvesTheTermTheCheckoutWouldBeChargedFor(
        int $sessionTerm,
        bool $sessionTermStillOffered,
        ?int $defaultTerm,
        int $expected,
        string $case
    ): void {
        $session = new CheckoutSession();
        $session->setTwoSelectedTerm($sessionTerm);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getDefaultPaymentTerm')->willReturn($defaultTerm);
        $config->method('isBuyerTermAvailable')->willReturn($sessionTermStillOffered);

        $this->assertSame(
            $expected,
            (new ChargedTermResolver($session, $config))->resolve(1),
            $case
        );
    }

    public function terms(): array
    {
        return [
            [14, true, 30, 14, 'the buyer picked an offered term'],
            [14, false, 30, 30, 'the picked term has since been withdrawn'],
            [0, true, 30, 30, 'no pick, so the configured default is charged'],
            [0, true, null, 0, 'no term is offered at all'],
        ];
    }
}
