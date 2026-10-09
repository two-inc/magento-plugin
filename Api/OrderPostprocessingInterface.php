<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Stable extension point: the last chance to edit an order request before it
 * is sent to Two (TWO-26092).
 *
 * Subscribe with an `after` plugin on process(). The default implementation
 * returns the payload unchanged; plugins chain by `sortOrder`, and the final
 * payload is sent as returned once it adds up, for Two's API to validate. The
 * plugin's own handler runs the shop-match checks only while no other handler
 * is registered (TWO-26276): see OrderPostprocessingShopMatchInterface. See
 * the README section "Stable extension contract: order postprocessing" for the
 * full contract.
 *
 * Fires on every order request: order_intent, order_create, order_update,
 * order_confirm, capture, refund and cancel. A subscriber must be a pure
 * function of its inputs.
 *
 * @api
 */
interface OrderPostprocessingInterface
{
    public const CONTRACT_VERSION = 1;

    public const REQUEST_ORDER_INTENT = 'order_intent';
    public const REQUEST_ORDER_CREATE = 'order_create';
    public const REQUEST_ORDER_UPDATE = 'order_update';
    public const REQUEST_ORDER_CONFIRM = 'order_confirm';
    public const REQUEST_CAPTURE = 'capture';
    public const REQUEST_REFUND = 'refund';
    public const REQUEST_CANCEL = 'cancel';

    /**
     * Return the request body to send.
     *
     * @param array $payload The complete request body exactly as it would be
     *                       sent: amounts as 2dp decimal strings. Empty for a
     *                       request with no body.
     * @param array $context request_type, trigger, endpoint, contract_version,
     *                       shipping_tax_rate, fallback_shipping_tax_rate, and
     *                       the platform objects: quote or order, plus
     *                       invoice, shipment or creditmemo where one exists.
     * @return array The full payload, edited or not.
     */
    public function process(array $payload, array $context): array;
}
