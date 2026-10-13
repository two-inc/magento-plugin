<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Two;

/**
 * TWO-26259, TWO-26340: a refused order create reaches the merchant's error
 * log once, with the API's reason and the quote it was for. The adapter
 * writes that line (see AdapterTest::refusalCases); this pins that the
 * create names its quote and that nothing else logs the same refusal.
 */
class TwoOrderCreateRefusalLogTest extends TestCase
{
    /**
     * authorize() needs the full framework to run, so pin the wiring from its
     * source, as the company-number guard's test does.
     */
    public function testTheCreateNamesItsQuoteAndIsLoggedOnce(): void
    {
        $method = new \ReflectionMethod(Two::class, 'authorize');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertMatchesRegularExpression(
            '/logSubject: \'quote \' \. \$order->getQuoteId\(\)\s*\);\s*'
            . '\$error = \$this->getErrorFromResponse\(\$response, true\);\s*'
            . 'if \(\$error\) \{\s*'
            . 'throw new LocalizedException\(\$error\);/',
            $source,
            'authorize() must name the quote for the adapter\'s refusal line, then throw without logging again.'
        );
        $this->assertFalse(
            method_exists(Two::class, 'logOrderCreateRefusal'),
            'a second create refusal line would log the refusal twice'
        );
    }
}
