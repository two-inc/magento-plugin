<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

/**
 * Failed verification return controller.
 *
 * The same return as the cancel link: the buyer comes back to pay for the
 * same basket another way, and what Two reports decides what happens to the
 * order (TWO-27389).
 */
class Verificationfailed extends Cancel
{
}
