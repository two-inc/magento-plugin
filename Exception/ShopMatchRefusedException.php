<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A shop-match check refused the request (TWO-26276): what the plugin built
 * from the shop's figures does not match the shop. Thrown by the order
 * postprocessing default handler, or by
 * Api\OrderPostprocessingShopMatchInterface::check() for a subscriber that
 * opts back in; the postprocessor passes it through as the refusal, never as
 * a subscriber failure.
 *
 * @api
 */
class ShopMatchRefusedException extends LocalizedException
{
}
