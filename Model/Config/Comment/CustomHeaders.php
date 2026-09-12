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
 * The admin help text under "Custom request headers", naming the active brand
 * through a placeholder rather than baking it into the string, so one
 * catalogue row translates the paragraph for every overlay brand.
 */
class CustomHeaders implements CommentInterface
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
            'Extra HTTP headers sent on every call this store makes to the %1 API, for merchants whose '
            . 'firewall or gateway requires one — a coarse network gate, not a credential. Tick "Also send '
            . 'from browser" only where your IT administrator needs the header on calls from the buyer\'s '
            . 'browser as well as those from your server: a ticked header is published to the buyer\'s '
            . 'browser and may be read by anyone. Check with support before ticking one — the browser\'s '
            . 'own direct call is refused unless %1 already allows that header name on it.',
            $this->brandRegistry->getProductName()
        );
    }
}
