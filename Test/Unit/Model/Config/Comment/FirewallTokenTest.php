<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Comment\FirewallToken;

class FirewallTokenTest extends TestCase
{
    public function testTheHelpTextNamesTheActiveBrandAndTheHeaderItIsSentAs(): void
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');

        $comment = (new FirewallToken($brandRegistry))->getCommentText(null);

        $this->assertStringContainsString('X-WAF-TOKEN', $comment);
        $this->assertStringContainsString('Acme Pay API', $comment);
        $this->assertStringNotContainsString('the Two API', $comment);
    }
}
