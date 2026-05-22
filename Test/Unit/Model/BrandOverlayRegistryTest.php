<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\BrandOverlayRegistry;

class BrandOverlayRegistryTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $registry = new BrandOverlayRegistry();
        $this->assertFalse($registry->isOverlayInstalled());
        $this->assertSame([], $registry->getOverlays());
    }

    public function testReportsInstalledWhenConstructorReceivesEntries(): void
    {
        $registry = new BrandOverlayRegistry(['abn' => 'abn_payment']);
        $this->assertTrue($registry->isOverlayInstalled());
        $this->assertSame(['abn' => 'abn_payment'], $registry->getOverlays());
    }
}
