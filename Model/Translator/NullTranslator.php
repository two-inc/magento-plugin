<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Translator;

use Two\Gateway\Api\TranslatorInterface;

/**
 * Default passthrough translator. Bound to {@see TranslatorInterface} by
 * etc/di.xml in vanilla installs. Brand overlays override the preference.
 *
 * Not `final`: integration-test spies may extend. Production should not.
 * Test spies inheriting via PassthroughTrait gain new methods on minor bumps;
 * spies that need full surface coverage must re-check after each upgrade.
 */
class NullTranslator implements TranslatorInterface
{
    use PassthroughTrait;
}
