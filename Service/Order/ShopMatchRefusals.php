<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Two\Gateway\Exception\ShopMatchRefusedException;
use WeakMap;

/**
 * The shop-match refusals the plugin's own checks raised in this request
 * (TWO-26309). The postprocessor lets only these through as a refusal; a
 * ShopMatchRefusedException a subscriber constructs itself is a fault in its
 * code. Shared, so the checks and the postprocessor see one record.
 */
class ShopMatchRefusals
{
    /**
     * @var WeakMap<ShopMatchRefusedException, true>
     */
    private $issued;

    public function __construct()
    {
        $this->issued = new WeakMap();
    }

    public function record(ShopMatchRefusedException $refusal): ShopMatchRefusedException
    {
        $this->issued[$refusal] = true;

        return $refusal;
    }

    public function issued(ShopMatchRefusedException $refusal): bool
    {
        return isset($this->issued[$refusal]);
    }
}
