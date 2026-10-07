<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Two\Gateway\Api\OrderPostprocessingInterface;

/**
 * Default binding for the order postprocessing hook: the payload unchanged.
 * Merchant code subscribes with an `after` plugin on process().
 */
class OrderPostprocessing implements OrderPostprocessingInterface
{
    /**
     * @inheritDoc
     */
    public function process(array $payload, array $context): array
    {
        return $payload;
    }
}
