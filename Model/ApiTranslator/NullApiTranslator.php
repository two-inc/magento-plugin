<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\ApiTranslator;

use Two\Gateway\Api\ApiTranslatorInterface;

/**
 * Default passthrough api-translator. Bound to {@see ApiTranslatorInterface}
 * by etc/di.xml in vanilla installs. Brand overlays rebind via <preference>.
 *
 * Not `final`: integration-test spies may extend. Production should not.
 */
class NullApiTranslator implements ApiTranslatorInterface
{
    use PassthroughTrait;
}
