<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Operation identifiers carried via {@see TranslatorInterface::OP_HEADER}.
 *
 * String-valued constants are enum-migration-compatible (PHP 8.1+ backed enum).
 */
final class Operation
{
    public const CREATE_ORDER     = 'createOrder';
    public const GET_ORDER        = 'getOrder';
    public const EDIT_ORDER       = 'editOrder';
    public const CONFIRM_ORDER    = 'confirmOrder';
    public const CANCEL_ORDER     = 'cancelOrder';
    public const REFUND_ORDER     = 'refundOrder';
    public const FULFILL_ORDER    = 'fulfillOrder';
    public const GET_FULFILLMENTS = 'getFulfillments';
    public const PRICE_ORDER_FEE  = 'priceOrderFee';
    public const MERCHANT_RATES   = 'merchantRates';
    public const VERIFY_API_KEY   = 'verifyApiKey';
    public const DELEGATION_TOKEN = 'delegationToken';
    public const AUTOFILL_TOKEN   = 'autofillToken';

    private function __construct() {}
}
