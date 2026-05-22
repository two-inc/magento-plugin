<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Two\Gateway\Api\Operation;
use Two\Gateway\Api\Webapi\SoleTraderInterface;
use Two\Gateway\Service\Api\Adapter;

class SoleTrader implements SoleTraderInterface
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * SoleTrader constructor.
     * @param Adapter $adapter
     */
    public function __construct(
        Adapter $adapter
    ) {
        $this->adapter = $adapter;
    }

    /**
     * @inheritDoc
     */
    public function getTokens(string $cartId): array
    {
        $delegationToken = $this->getDelegationToken();
        $autofillToken = $this->getAutofillToken();

        return [['delegation_token' => $delegationToken, 'autofill_token' => $autofillToken]];
    }

    private function getDelegationToken(): string
    {
        $delegateResponse = $this->adapter->execute(
            self::DELEGATION_TOKEN_ENDPOINT,
            ['create_proposal' => true, 'read_current_business' => true],
            'POST',
            null,
            Operation::DELEGATION_TOKEN
        );
        return $this->extractToken($delegateResponse);
    }

    private function getAutofillToken(): string
    {
        $autofillResponse = $this->adapter->execute(
            self::AUTOFILL_TOKEN_ENDPOINT,
            ['read_current_buyer' => true, 'write_current_buyer' => true],
            'POST',
            null,
            Operation::AUTOFILL_TOKEN
        );
        return $this->extractToken($autofillResponse);
    }

    /**
     * The token-endpoint Adapter contract returns a lowercase-keyed headers array on
     * success. A missing header is either (a) an upstream regression we should not
     * paper over with an empty token, or (b) a translator failure that the Adapter
     * has already escalated to error_code=502; both surface to the caller as failure.
     */
    private function extractToken(array $response): string
    {
        if (isset($response['error_code'])) {
            return '';
        }
        return (string)($response['two-delegated-authority-token'] ?? '');
    }
}
