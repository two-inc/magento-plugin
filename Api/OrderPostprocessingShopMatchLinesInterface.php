<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

use Two\Gateway\Exception\ShopMatchRefusedException;

/**
 * The shop-match checks made for single lines only (TWO-26309), for a
 * subscriber that declares its own split of the order, so the whole-order
 * comparison with the shop's totals cannot refuse that split. A separate
 * interface, so a class implementing OrderPostprocessingShopMatchInterface
 * is unaffected. See the README section "Stable extension contract: order
 * postprocessing".
 *
 * @api
 */
interface OrderPostprocessingShopMatchLinesInterface
{
    /**
     * As OrderPostprocessingShopMatchInterface::check(), without the
     * whole-order check.
     *
     * @param array $result The payload you are about to return.
     * @param array $payload The payload as the plugin composed it.
     * @param array $context process()'s $context argument.
     * @return void
     * @throws ShopMatchRefusedException with the refusal the shop's figures earn
     */
    public function checkLines(array $result, array $payload, array $context): void;
}
