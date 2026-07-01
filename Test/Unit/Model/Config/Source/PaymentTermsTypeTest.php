<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Source\PaymentTermsType;

class PaymentTermsTypeTest extends TestCase
{
    public function testOptionArrayIncludesStandardAndEndOfMonth(): void
    {
        $source = new PaymentTermsType();
        $values = array_column($source->toOptionArray(), 'value');
        $this->assertContains(PaymentTermsType::STANDARD, $values);
        $this->assertContains(PaymentTermsType::END_OF_MONTH, $values);
    }
}
