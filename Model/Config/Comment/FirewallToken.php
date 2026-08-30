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
 * The admin help text under "Firewall Token", naming the active brand rather
 * than hardcoding "Two" so a brand overlay needs no override file.
 */
class FirewallToken implements CommentInterface
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
            'If your IT administrator asks you to add a firewall token, place it in this field. It will then '
            . 'be transmitted as header X-WAF-TOKEN on all calls to the %1 API. This is a coarse network gate, '
            . 'not a secret credential.',
            $this->brandRegistry->getProductName()
        );
    }
}
