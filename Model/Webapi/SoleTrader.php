<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Two\Gateway\Api\Webapi\SoleTraderInterface;
use Two\Gateway\Service\Api\Adapter;
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

    public function __construct(
        Adapter $adapter,
        SupportedCompanyTypes $supportedCompanyTypes,
        RateLimiter $rateLimiter,
        CheckoutSession $checkoutSession
    ) {
        $this->adapter = $adapter;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
        $this->rateLimiter = $rateLimiter;
        $this->checkoutSession = $checkoutSession;
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

        $storeId = $this->quoteStoreId();
        $delegationToken = $this->getDelegationToken($storeId);
        $autofillToken = $this->getAutofillToken($storeId);

        return [['delegation_token' => $delegationToken, 'autofill_token' => $autofillToken]];
    }

    private function getDelegationToken(?int $storeId): string
    {
        $delegateResponse = $this->adapter->execute(
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
        $autofillResponse = $this->adapter->execute(
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
