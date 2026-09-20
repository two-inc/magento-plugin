<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Framework\Cache\Config;

use Magento\Framework\Cache\ConfigInterface;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Fills the `%1` in this module's `etc/cache.xml` label and description with
 * the active brand's provider name, so Cache Management carries no literal
 * product name that a debranded install would have to translate away.
 */
class BrandCacheTypeLabels
{
    private const CACHE_TYPE = 'two_gateway';

    public function __construct(
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    /**
     * @param array<string,array<string,string>> $result
     * @return array<string,array<string,string>>
     */
    public function afterGetTypes(ConfigInterface $subject, array $result): array
    {
        if (isset($result[self::CACHE_TYPE])) {
            $result[self::CACHE_TYPE] = $this->render($result[self::CACHE_TYPE]);
        }

        return $result;
    }

    /**
     * @param array<string,string> $result
     * @return array<string,string>
     */
    public function afterGetType(ConfigInterface $subject, array $result, string $identifier): array
    {
        return $identifier === self::CACHE_TYPE ? $this->render($result) : $result;
    }

    /**
     * @param array<string,string> $type
     * @return array<string,string>
     */
    private function render(array $type): array
    {
        try {
            $provider = $this->brandRegistry->getProvider();
        } catch (\Throwable $e) {
            // Cache Management, and every cache flush behind it, must survive
            // an unresolvable brand; the untouched %1 is the lesser failure.
            return $type;
        }

        foreach (['label', 'description'] as $key) {
            if (isset($type[$key]) && $type[$key] !== '') {
                $type[$key] = (string)__($type[$key], $provider);
            }
        }

        return $type;
    }
}
