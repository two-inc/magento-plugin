<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Two\Gateway\Model\Cache\Type\TwoGateway;

/**
 * Enables the cache types this module declares in etc/cache.xml.
 *
 * A type absent from env.php resolves as disabled, and cache.xml carries no
 * default-state attribute, so an install has to write the state itself.
 * Runs once, so a merchant who later disables the type keeps it disabled.
 */
class EnableGatewayCacheType implements DataPatchInterface
{
    /** Must list every type in etc/cache.xml; EnableGatewayCacheTypeTest reads that file and fails otherwise. */
    public const DECLARED_CACHE_TYPES = [TwoGateway::TYPE_IDENTIFIER];

    /**
     * @var CacheManager
     */
    private $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->cacheManager->setEnabled(self::DECLARED_CACHE_TYPES, true);

        return $this;
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
