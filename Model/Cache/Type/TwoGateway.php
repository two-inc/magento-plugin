<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Cache type for values fetched from Two (the merchant record), so
 * `cache:clean two_gateway` drops them without touching Magento's config
 * cache, and a config clean leaves them alone.
 */
class TwoGateway extends TagScope
{
    public const TYPE_IDENTIFIER = 'two_gateway';

    public const CACHE_TAG = 'TWO_GATEWAY';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
