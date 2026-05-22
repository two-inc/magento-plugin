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

    /**
     * @return string
     */

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
     * success. Discriminate on positive presence of the token (success) rather than
     * presence of `error_code` — the latter could collide with a real lowercase
     * response header literally named `error_code` from a misbehaving upstream.
     */
    private function extractToken(array $response): string
    {
        $token = $response['two-delegated-authority-token'] ?? null;
        if (!is_string($token) || $token === '') {
            return '';
        }
        return $token;
    }
}
