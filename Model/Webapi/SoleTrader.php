<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Two\Gateway\Api\Webapi\SoleTraderInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;

class SoleTrader implements SoleTraderInterface
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var SupportedCompanyTypes
     */
    private $supportedCompanyTypes;

    /**
     * SoleTrader constructor.
     * @param Adapter $adapter
     * @param SupportedCompanyTypes $supportedCompanyTypes
     */
    public function __construct(
        Adapter $adapter,
        SupportedCompanyTypes $supportedCompanyTypes
    ) {
        $this->adapter = $adapter;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedCompanyTypes(string $countryCode): array
    {
        return $this->supportedCompanyTypes->getForCountry($countryCode);
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
            ['create_proposal' => true, 'read_current_business' => true]
        );
        if (isset($delegateResponse['two-delegated-authority-token'])) {
            return $delegateResponse['two-delegated-authority-token'];
        } else {
            return '';
        }
    }

    private function getAutofillToken()
    {
        $autofillResponse = $this->adapter->execute(
            self::AUTOFILL_TOKEN_ENDPOINT,
            ['read_current_buyer' => true, 'write_current_buyer' => true]
        );
        if (isset($autofillResponse['two-delegated-authority-token'])) {
            return $autofillResponse['two-delegated-authority-token'];
        } else {
            return '';
        }
    }
}
