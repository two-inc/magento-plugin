<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Webapi\SoleTraderInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\IdempotencyKey;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\RateLimiter;

class SoleTrader implements SoleTraderInterface
{
    /** One lookup per billing-country edit; answered from a server-side cache. */
    private const COMPANY_TYPES_LIMIT_PER_MINUTE = 60;

    /**
     * Two upstream token mints per call, one popup flow per buyer — with
     * headroom for a whole office sharing one NAT address.
     */
    private const TOKENS_LIMIT_PER_MINUTE = 60;

    private const WINDOW_SECONDS = 60;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var SupportedCompanyTypes
     */
    private $supportedCompanyTypes;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private $quoteIdToMaskedQuoteId;

    public function __construct(
        Adapter $adapter,
        SupportedCompanyTypes $supportedCompanyTypes,
        RateLimiter $rateLimiter,
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository,
        QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
    ) {
        $this->adapter = $adapter;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
        $this->rateLimiter = $rateLimiter;
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedCompanyTypes(string $countryCode): array
    {
        $this->rateLimiter->assertWithinLimit(
            'two_supported_company_types',
            self::COMPANY_TYPES_LIMIT_PER_MINUTE,
            self::WINDOW_SECONDS
        );

        return $this->supportedCompanyTypes->getForCountry($countryCode, $this->quoteStoreId());
    }

    /**
     * @inheritDoc
     */
    public function getTokens(string $cartId): array
    {
        $this->rateLimiter->assertWithinLimit(
            'two_sole_trader_tokens',
            self::TOKENS_LIMIT_PER_MINUTE,
            self::WINDOW_SECONDS
        );

        $this->assertOwnCheckoutCart($cartId);

        $storeId = $this->quoteStoreId();
        $delegationToken = $this->getDelegationToken($storeId);
        $autofillToken = $this->getAutofillToken($storeId);

        return [['delegation_token' => $delegationToken, 'autofill_token' => $autofillToken]];
    }

    /**
     * Tokens are a credential for one buyer's signup, so they are minted only
     * for the session's own active quote, with items, on a store where this
     * method is enabled, and only when the caller names that quote: by its
     * masked id (a guest on the standard checkout) or its own id (a customer,
     * or any buyer on checkouts that send it). Anything else is refused
     * before Two is called (TWO-27057).
     *
     * @throws WebapiException
     */
    private function assertOwnCheckoutCart(string $cartId): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $quoteId = (int)$quote->getId();
            $allowed = $quoteId > 0
                && (bool)$quote->getIsActive()
                && (int)$quote->getItemsCount() > 0
                && $this->configRepository->isActive($this->quoteStoreId())
                && $this->namesQuote($cartId, $quoteId);
        } catch (\Throwable $e) {
            $allowed = false;
        }
        if (!$allowed) {
            throw new WebapiException(
                __('Sole trader sign-up is not available for this cart.'),
                0,
                WebapiException::HTTP_FORBIDDEN
            );
        }
    }

    private function namesQuote(string $cartId, int $quoteId): bool
    {
        if ($cartId === '') {
            return false;
        }
        if (hash_equals((string)$quoteId, $cartId)) {
            return true;
        }
        $maskedId = (string)$this->quoteIdToMaskedQuoteId->execute($quoteId);

        return $maskedId !== '' && hash_equals($maskedId, $cartId);
    }

    private function getDelegationToken(?int $storeId): string
    {
        $delegateResponse = $this->adapter->withIdempotencyKey($this->tokenKey('delegation_token'))->execute(
            self::DELEGATION_TOKEN_ENDPOINT,
            ['create_proposal' => true, 'read_current_business' => true],
            'POST',
            $storeId
        );
        if (isset($delegateResponse['two-delegated-authority-token'])) {
            return $delegateResponse['two-delegated-authority-token'];
        } else {
            return '';
        }
    }

    private function getAutofillToken(?int $storeId)
    {
        $autofillResponse = $this->adapter->withIdempotencyKey($this->tokenKey('autofill_token'))->execute(
            self::AUTOFILL_TOKEN_ENDPOINT,
            ['read_current_buyer' => true, 'write_current_buyer' => true],
            'POST',
            $storeId
        );
        if (isset($autofillResponse['two-delegated-authority-token'])) {
            return $autofillResponse['two-delegated-authority-token'];
        } else {
            return '';
        }
    }

    /**
     * Read off the quote, not the request: this route is reached at
     * /rest/V1/... with no store code, so the request resolves to the default
     * store. Null falls the call back to the default scope.
     */
    /**
     * A token request is named by the buyer's quote and session (TWO-26658).
     * A token is a credential for that buyer, so without both there is no
     * key at all rather than one another buyer could share.
     */
    private function tokenKey(string $operation): ?string
    {
        try {
            $quoteId = (string)$this->checkoutSession->getQuote()->getId();
        } catch (\Throwable $e) {
            $quoteId = '';
        }
        $sessionId = (string)$this->checkoutSession->getSessionId();
        if ($quoteId === '' || $sessionId === '') {
            return null;
        }

        return IdempotencyKey::build($operation, $quoteId, IdempotencyKey::hash($sessionId));
    }

    private function quoteStoreId(): ?int
    {
        try {
            $storeId = (int)$this->checkoutSession->getQuote()->getStoreId();
        } catch (\Throwable $e) {
            return null;
        }

        return $storeId > 0 ? $storeId : null;
    }
}
