<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Webapi\TermSelection;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * ABN-387. The selectTerm endpoint mutates persisted state via
 * setTwoSelectedTerm + cartRepository->save; an unvalidated termDays
 * therefore poisons the session and downstream the order placed on
 * the Two API. Tests pin:
 *
 *  - termDays MUST be in getAllBuyerTerms($storeId) before any state
 *    mutation; otherwise InputException (HTTP 400 via webapi).
 *  - Validation runs BEFORE setTwoSelectedTerm — an invalid call must
 *    not poison the session even on the throw path. (The original bug.)
 *  - $cartId is ignored — same anonymous-route auth-boundary pattern as
 *    ABN-374's /surcharges fix; regression guard.
 *  - Empty configured terms (merchant has the method enabled but
 *    configured no terms) rejects every input.
 */
class TermSelectionTest extends TestCase
{
    /** @var TSFakeCheckoutSession */
    private $session;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var TSFakeCartRepository */
    private $cartRepo;

    /** @var TSFakeCartTotalRepository */
    private $cartTotalRepo;

    /** @var TSFakeSurchargeCalculator */
    private $calculator;

    /** @var TermSelection */
    private $endpoint;

    protected function setUp(): void
    {
        $this->session = new TSFakeCheckoutSession();
        $this->config = $this->createMock(ConfigRepository::class);
        $this->cartRepo = new TSFakeCartRepository();
        $this->cartTotalRepo = new TSFakeCartTotalRepository();
        $this->calculator = new TSFakeSurchargeCalculator();

        $this->endpoint = new TermSelection(
            $this->session,
            $this->cartRepo,
            $this->cartTotalRepo,
            $this->config,
            $this->calculator
        );

        // Default success-path scaffolding — quote on session, configured
        // terms include 14 and 30.
        $this->session->quote = new TSFakeQuote(['id' => 7, 'store_id' => 1]);
        $this->config->method('getAllBuyerTerms')->willReturn([14, 30]);
    }

    public function testValidTermPersistsAndReturnsPayload(): void
    {
        $payload = $this->endpoint->selectTerm('any-cart-id', 30);

        $this->assertSame([30], $this->session->setSelectedTermCalls);
        $this->assertCount(1, $this->cartRepo->savedQuotes);
        // Magento's webapi serializer wraps in an outer array — preserve
        // that contract.
        $this->assertCount(1, $payload);
        $this->assertArrayHasKey('term_surcharges', $payload[0]);
        $this->assertArrayHasKey('total_segments', $payload[0]);
    }

    public function testInvalidTermThrowsInputExceptionAndDoesNotPoisonSession(): void
    {
        // The original ABN-387 bug: pre-fix, setTwoSelectedTerm fired
        // BEFORE any validation, so even an exception-throwing path
        // would persist the attacker-supplied term to the session.
        // The fix reorders validation first; this test pins that
        // ordering — regression would re-poison the session.
        try {
            $this->endpoint->selectTerm('any-cart-id', 9999);
            $this->fail('Expected InputException for unconfigured term');
        } catch (InputException $e) {
            // expected
        }

        $this->assertSame(
            [],
            $this->session->setSelectedTermCalls,
            'session must not be mutated on the validation-failure path'
        );
        $this->assertCount(0, $this->cartRepo->savedQuotes);
    }

    public function testZeroTermRejected(): void
    {
        $this->expectException(InputException::class);
        $this->endpoint->selectTerm('any-cart-id', 0);
    }

    public function testNegativeTermRejected(): void
    {
        $this->expectException(InputException::class);
        $this->endpoint->selectTerm('any-cart-id', -1);
    }

    public function testEmptyConfiguredTermsRejectsEverything(): void
    {
        // Merchant has the payment method enabled but configured no
        // terms (or all configured terms have been removed) — every
        // input must be rejected. Better to surface than silently
        // persist an unconfigured term.
        $endpoint = new TermSelection(
            $this->session,
            $this->cartRepo,
            $this->cartTotalRepo,
            $this->buildConfigReturning([]),
            $this->calculator
        );

        $this->expectException(InputException::class);
        $endpoint->selectTerm('any-cart-id', 30);
    }

    public function testCartIdIgnored(): void
    {
        // Regression guard mirroring ABN-374's testIgnoresCartIdPathParameter.
        // Endpoint must derive no trust from the URL parameter — it cannot
        // be authenticated on an anonymous route. A mismatched / bogus
        // cartId must not affect which quote we operate on.
        $payload = $this->endpoint->selectTerm('completely-bogus-cart-id', 14);

        $this->assertSame([14], $this->session->setSelectedTermCalls);
        $this->assertSame(
            [$this->session->quote],
            $this->cartRepo->savedQuotes,
            'must operate on the session quote regardless of cartId argument'
        );
        $this->assertNotEmpty($payload);
    }

    public function testValidationPrecedesAnyStateMutation(): void
    {
        // Belt-and-braces ordering pin: even collectTotals (which reruns
        // the surcharge collector and could fire pricing-API calls) must
        // not run on the rejection path. Establishing this invariant
        // makes future refactors that move work earlier in selectTerm
        // visibly fail this test.
        try {
            $this->endpoint->selectTerm('any-cart-id', 9999);
            $this->fail('Expected InputException');
        } catch (InputException $e) {
            // expected
        }

        $this->assertSame(0, $this->session->quote->collectTotalsCalls);
    }

    private function buildConfigReturning(array $terms)
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getAllBuyerTerms')->willReturn($terms);
        return $config;
    }
}

class TSFakeCheckoutSession extends CheckoutSession
{
    /** @var TSFakeQuote|null */
    public $quote = null;

    /** @var array<int, int> */
    public $setSelectedTermCalls = [];

    /** @var float */
    public $surchargeGross = 0.0;

    public function __construct()
    {
        // Skip Magento DI graph.
    }

    public function getQuote()
    {
        return $this->quote;
    }

    public function setTwoSelectedTerm($termDays)
    {
        $this->setSelectedTermCalls[] = (int)$termDays;
        return $this;
    }

    public function getTwoSurchargeGross()
    {
        return $this->surchargeGross;
    }
}

class TSFakeQuote
{
    /** @var int */
    public $collectTotalsCalls = 0;

    /** @var array<string, mixed> */
    private $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function collectTotals(): self
    {
        $this->collectTotalsCalls++;
        return $this;
    }

    public function getId()
    {
        return $this->data['id'] ?? 1;
    }

    public function getStoreId()
    {
        return $this->data['store_id'] ?? 1;
    }

    public function getQuoteCurrencyCode()
    {
        return 'EUR';
    }

    public function getStore()
    {
        return new class {
            public function getBaseCurrencyCode()
            {
                return 'EUR';
            }
        };
    }

    public function getBillingAddress()
    {
        return null;
    }

    public function getShippingAddress()
    {
        return null;
    }
}

class TSFakeCartRepository implements CartRepositoryInterface
{
    /** @var array<int, mixed> */
    public $savedQuotes = [];

    public function save($quote)
    {
        $this->savedQuotes[] = $quote;
    }
}

class TSFakeCartTotalRepository implements CartTotalRepositoryInterface
{
    public function get($cartId)
    {
        return new class {
            public function getTotalSegments()
            {
                return [];
            }

            public function getGrandTotal()
            {
                return 100.0;
            }

            public function getBaseGrandTotal()
            {
                return 100.0;
            }

            public function getTaxAmount()
            {
                return 0.0;
            }
        };
    }
}

class TSFakeSurchargeCalculator extends SurchargeCalculator
{
    public function __construct()
    {
        // Skip parent constructor — fake doesn't need DI graph.
    }

    public function calculate(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        string $orderCurrency,
        ?int $storeId = null
    ): array {
        return ['amount' => 0.0, 'tax_rate' => 0.0, 'description' => ''];
    }
}
