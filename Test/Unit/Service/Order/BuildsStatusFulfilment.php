<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Two\Gateway\Service\Order\FulfilmentDeferral;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * Builds StatusFulfilment through its real constructor: each collaborator is
 * the one named in $collaborators, or a plain mock of its declared type, with
 * a fresh deferral and an idle sales connection by default.
 */
trait BuildsStatusFulfilment
{
    private function buildStatusFulfilment(array $collaborators): StatusFulfilment
    {
        $collaborators += [
            'deferral' => new FulfilmentDeferral(),
            'orderResource' => new FakeOrderResource(),
        ];
        $arguments = [];
        foreach ((new \ReflectionMethod(StatusFulfilment::class, '__construct'))->getParameters() as $parameter) {
            $arguments[] = $collaborators[$parameter->getName()]
                ?? $this->createMock((string)$parameter->getType());
        }

        return new StatusFulfilment(...$arguments);
    }
}
