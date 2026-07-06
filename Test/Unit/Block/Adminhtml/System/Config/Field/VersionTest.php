<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\Version;

/**
 * Tests for commit-SHA resolution in the admin Version panel.
 *
 * The regression (TWO-25020): composer-installed deploys put the module under
 * vendor/ with no .git worktree, so the path-based resolution returned '' and
 * the panel showed '—'. extractCommit() now prefers Composer's installed
 * registry (source/dist reference), falling back to the .git/worktree parse.
 */
class VersionTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/two-version-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.git', '/composer.json', '/registration.php'] as $f) {
            @unlink($this->tmpDir . $f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeComposerJson(string $name): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            json_encode(['name' => $name])
        );
    }

    public function testCommitResolvedFromComposerReference(): void
    {
        $this->writeComposerJson('two-inc/magento2');
        $block = new VersionTestable();
        $block->stubRef = '0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd';

        $this->assertSame('0aa2194', $block->commitFromComposerPublic($this->tmpDir));
    }

    public function testComposerReferenceIsPreferredOverGitWorktree(): void
    {
        // Both signals present: composer wins (it's the authoritative,
        // layout-independent source for a composer-installed module).
        $this->writeComposerJson('two-inc/magento2');
        file_put_contents($this->tmpDir . '/.git', "gitdir: /repo/.git/worktrees/deadbeef1234\n");
        $block = new VersionTestable();
        $block->stubRef = '0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd';

        $this->assertSame('0aa2194', $block->extractCommitPublic($this->tmpDir));
    }

    public function testFallsBackToGitWorktreeWhenNotComposerInstalled(): void
    {
        // composer.json present but the package resolves no reference (null) —
        // e.g. a git-sync/dev checkout — so the .git worktree parse takes over.
        $this->writeComposerJson('two-inc/magento2');
        file_put_contents($this->tmpDir . '/.git', "gitdir: /repo/.git/worktrees/abcdef1234567\n");
        $block = new VersionTestable();
        $block->stubRef = null;

        $this->assertSame('abcdef1', $block->extractCommitPublic($this->tmpDir));
    }

    public function testNonHexReferenceIsRejected(): void
    {
        // A path-repo / branch install can carry a non-SHA reference; it must
        // not be shown as a commit — return null so the caller falls back.
        $this->writeComposerJson('two-inc/magento2');
        $block = new VersionTestable();
        $block->stubRef = 'dev-main';

        $this->assertNull($block->commitFromComposerPublic($this->tmpDir));
    }

    public function testEmptyWhenNoComposerAndNoGit(): void
    {
        $block = new VersionTestable();
        $block->stubRef = null;

        $this->assertSame('', $block->extractCommitPublic($this->tmpDir));
    }

    public function testPackageNameReadFromParentDirForMonorepoSubpath(): void
    {
        // Monorepo sub-path modules keep composer.json one level up.
        $sub = $this->tmpDir . '/plugin';
        mkdir($sub);
        $this->writeComposerJson('two-inc/magento2');
        $block = new VersionTestable();
        $block->stubRef = '0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd';

        $this->assertSame('0aa2194', $block->commitFromComposerPublic($sub));
        @rmdir($sub);
    }
}

/**
 * Constructor-free subclass exposing the protected resolution methods and
 * stubbing the static Composer registry lookup.
 */
class VersionTestable extends Version
{
    /** @var string|null */
    public $stubRef = null;

    // Skip the heavy Field base constructor — these tests exercise pure
    // resolution logic that needs no injected dependencies.
    public function __construct()
    {
    }

    protected function composerReference(string $packageName): ?string
    {
        return $this->stubRef;
    }

    public function commitFromComposerPublic(string $modulePath): ?string
    {
        return $this->commitFromComposer($modulePath);
    }

    public function extractCommitPublic(string $modulePath): string
    {
        return $this->extractCommit($modulePath);
    }
}
