<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\Version;
use Two\Gateway\Model\Provenance;

/**
 * The admin Version panel's per-module commit column.
 *
 * The resolution logic itself moved to Two\Gateway\Model\Provenance in
 * TWO-25197 (Model\Config\Repository needs the same SHA for `client_v`);
 * see Test\Unit\Model\ProvenanceTest for the composer / gitlink / neither
 * cases. What matters here is that the block still resolves per module
 * path, so an overlay row reports the overlay's own commit rather than
 * the base module's.
 */
class VersionTest extends TestCase
{
    public function testExtractCommitResolvesPerModulePath(): void
    {
        $provenance = $this->createMock(Provenance::class);
        $provenance->method('commitForPath')->willReturnMap([
            ['/app/code/Two/Gateway', '6f8534e'],
            ['/app/code/Overlay/Gateway', 'cd9edfb'],
        ]);

        $block = new VersionTestable();
        $block->setProvenance($provenance);

        $this->assertSame('6f8534e', $block->extractCommitPublic('/app/code/Two/Gateway'));
        $this->assertSame('cd9edfb', $block->extractCommitPublic('/app/code/Overlay/Gateway'));
    }

    public function testUnresolvableCommitIsEmptyNotAnException(): void
    {
        $provenance = $this->createMock(Provenance::class);
        $provenance->method('commitForPath')->willReturn('');

        $block = new VersionTestable();
        $block->setProvenance($provenance);

        $this->assertSame('', $block->extractCommitPublic('/app/code/Two/Gateway'));
    }
}

/**
 * Constructor-free subclass exposing the protected commit lookup. The
 * heavy Field base constructor is skipped — this exercises resolution
 * wiring only, which needs no injected framework dependencies.
 */
class VersionTestable extends Version
{
    public function __construct()
    {
    }

    public function setProvenance(Provenance $provenance): void
    {
        $this->provenance = $provenance;
    }

    public function extractCommitPublic(string $modulePath): string
    {
        return $this->extractCommit($modulePath);
    }
}
