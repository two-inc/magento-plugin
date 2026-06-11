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
 * Shows the merchant the platform/partner minimum their own value must
 * exceed (the brand's minimum_order), so the constraint on the field is
 * visible where they type.
 */
class MerchantMinimumOrder implements CommentInterface
{
    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(BrandRegistryInterface $brandRegistry)
    {
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * @inheritDoc
     */
    public function getCommentText($elementValue)
    {
        $platformMinimum = $this->brandRegistry->getMinimumOrder();
        if ($platformMinimum === null) {
            return (string)__(
                'Hide the payment method below this order value (store base currency, including tax). Leave empty for no minimum.'
            );
        }
        return (string)__(
            'Platform minimum: %1 %2 (%3 tax). A value here must exceed it and is interpreted in the same currency and tax basis.',
            $platformMinimum['amount'],
            $platformMinimum['currency'],
            $platformMinimum['basis'] === 'gross' ? __('including') : __('excluding')
        );
    }
}
