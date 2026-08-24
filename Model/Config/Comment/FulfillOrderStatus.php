<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Comment;

use Magento\Config\Model\Config\CommentInterface;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * The admin help text under "Fulfilment order status", naming the active
 * brand rather than hardcoding "Two" so a brand overlay needs no override
 * file for this field.
 */
class FulfillOrderStatus implements CommentInterface
{
    public function __construct(
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCommentText($elementValue)
    {
        return (string)__(
            'If fulfilment trigger is On Completion, select one or more order statuses which can trigger '
            . 'fulfilment. <strong>Leave this empty and no order status triggers fulfilment, so %1 is never '
            . 'notified that an order was fulfilled.</strong>',
            $this->brandRegistry->getProductName()
        );
    }
}
