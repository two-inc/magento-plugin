<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

use Two\Gateway\Exception\ShopMatchRefusedException;

/**
 * The shop-match checks (TWO-26276): whether the lines the plugin built from
 * the shop's own figures match what the shop worked out.
 *
 * The plugin's default handler on OrderPostprocessingInterface runs them
 * when no other subscriber is registered. A subscriber takes over
 * responsibility for them; inject this to opt back in, for the payload you
 * return or for the parts you did not touch. See the README section "Stable
 * extension contract: order postprocessing".
 *
 * @api
 */
interface OrderPostprocessingShopMatchInterface
{
    /**
     * Refuse as the default handler would.
     *
     * Each check applies to the line the plugin built it for, and only while
     * $result still carries that line unchanged: a line you edited, replaced
     * or removed is yours.
     *
     * @param array $result The payload you are about to return.
     * @param array $payload The payload as the plugin composed it: process()'s $payload argument.
     * @param array $context process()'s $context argument.
     * @return void
     * @throws ShopMatchRefusedException with the refusal the shop's figures earn
     */
    public function check(array $result, array $payload, array $context): void;
}
