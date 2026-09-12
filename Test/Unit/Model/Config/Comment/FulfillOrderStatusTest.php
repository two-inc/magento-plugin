<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Comment\FulfillOrderStatus;

/**
 * TWO-25503: the "never notified" warning must name the active brand, not
 * hardcode "Two", so an overlay needs no per-brand override for this field.
 */
class FulfillOrderStatusTest extends TestCase
{
    public function testCommentNamesTheActiveBrandProductName(): void
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        $comment = (new FulfillOrderStatus($brandRegistry))->getCommentText(null);

        $this->assertStringContainsString(
            'so Acme Pay is never notified that an order was fulfilled.',
            $comment,
            'comment must relay the active brand\'s product name, not a hardcoded brand'
        );
        $this->assertStringNotContainsString('Two is never notified', $comment);
    }
}
