<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee;

use Two\Gateway\Api\Fee\FeeLineProviderInterface;

/**
 * Aggregates all registered FeeLineProviderInterface implementations.
 *
 * Injected as an array via di.xml (Magento's TMAP pattern) so providers
 * can be added later without touching Order/ComposeOrder/ComposeCapture/
 * ComposeRefund. Defaults to an empty array — see etc/di.xml, no concrete
 * providers are wired in yet.
 */
class FeeLineProviderPool
{
    /**
     * @var FeeLineProviderInterface[]
     */
    private $providers;

    /**
     * @param FeeLineProviderInterface[] $providers
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * @param \Magento\Sales\Model\Order|\Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array
    {
        $lines = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getFeeLines($entity) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
