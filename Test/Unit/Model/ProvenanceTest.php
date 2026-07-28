<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Provenance;

/**
 * Commit-SHA resolution for the deployed module (TWO-25197, TWO-25205).
 *
 * Three deploy shapes must resolve, plus a fourth that must degrade quietly:
 *  - gitSync dev install: no composer package; `.git` is a gitlink FILE
 *    naming the worktree after its SHA.
 *  - Packagist/composer install: no .git at all; the installed registry
 *    carries the release SHA (the regression fixed in TWO-25020).
 *  - zip drop (the ABN overlay's GCS/release-asset zips): neither a .git nor
 *    a Composer registry entry, only the `.two-deployed-commit` build stamp
 *    `make archive` injects (TWO-25205).
 *  - none of the three: bare '' with no exception.
 *
 * Order is gitlink → composer → stamp, one org-wide order across all six Two
 * plugin artifacts, ranked by freshness: the gitlink tracks what is checked
 * out right now, the composer reference is fixed at install time, the stamp
 * is frozen at build time. A malformed signal must fall THROUGH, not win.
 *
 * Logic previously lived in the admin Version block; this suite is its
 * home now that Model\Config\Repository consumes it too.
 */
class ProvenanceTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/two-provenance-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.git', '/composer.json', '/registration.php', '/.two-deployed-commit'] as $f) {
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

    private function writeStamp(string $contents, ?string $dir = null): void
    {
        file_put_contents(($dir ?? $this->tmpDir) . '/.two-deployed-commit', $contents);
    }

    private function provenance(?string $stubRef): ProvenanceTestable
    {
        $p = new ProvenanceTestable($this->createMock(ComponentRegistrar::class));
        $p->stubRef = $stubRef;

        return $p;
    }

    public function testCommitResolvedFromComposerReference(): void
    {
        $this->writeComposerJson('two-inc/magento2');
        $p = $this->provenance('6f8534ed11ce70d739b3cd910e27d991d508b3f6');

        $this->assertSame('6f8534e', $p->commitFromComposer($this->tmpDir));
        $this->assertSame('6f8534e', $p->commitForPath($this->tmpDir));
    }

    public function testGitlinkIsPreferredOverComposerReference(): void
    {
        // Both signals present: the gitlink wins (TWO-25205). It reflects
        // what is checked out right now; the composer reference was recorded
        // once at install time and can be older.
        $this->writeComposerJson('two-inc/magento2');
        file_put_contents($this->tmpDir . '/.git', "gitdir: /repo/.git/worktrees/deadbeef1234\n");
        $p = $this->provenance('0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd');

        $this->assertSame('deadbee', $p->commitForPath($this->tmpDir));
    }

    public function testGitlinkIsPreferredOverStamp(): void
    {
        // Gitlink beats the build stamp: the stamp is frozen at build time
        // and is the staler of the two after any gitSync pull.
        file_put_contents($this->tmpDir . '/.git', "gitdir: /repo/.git/worktrees/deadbeef1234\n");
        $this->writeStamp("fedcba9876543210fedcba9876543210fedcba98\n");

        $this->assertSame('deadbee', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testComposerReferenceIsPreferredOverStamp(): void
    {
        // No gitlink, so composer (install time) beats the stamp (build time).
        $this->writeComposerJson('two-inc/magento2');
        $this->writeStamp("fedcba9876543210fedcba9876543210fedcba98\n");
        $p = $this->provenance('0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd');

        $this->assertSame('0aa2194', $p->commitForPath($this->tmpDir));
    }

    public function testStampResolvesWhenNeitherGitNorComposerPresent(): void
    {
        // The zip-drop shape: the ABN overlay's GCS/release-asset zips carry
        // no .git and no Composer registry entry, so the `make archive`
        // stamp is the only provenance signal that exists (TWO-25205).
        $this->writeStamp("fedcba9876543210fedcba9876543210fedcba98\n");

        $this->assertSame('fedcba9', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testStampAcceptsShortShaAsWrittenByMakeArchive(): void
    {
        // `make archive` writes `git rev-parse --short HEAD` — 7 chars, not 40.
        $this->writeStamp("e26ee58\n");

        $this->assertSame('e26ee58', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testStampFoundOneLevelUpForSubPathModule(): void
    {
        // A repo shipping modules from sub-paths (the ABN overlay's plugin/
        // and hyva/) has the stamp at the archive root, one level up — the
        // same two-place lookup the gitlink and composer.json use.
        $sub = $this->tmpDir . '/plugin';
        mkdir($sub);
        $this->writeStamp("fedcba9876543210fedcba9876543210fedcba98\n");

        $this->assertSame('fedcba9', $this->provenance(null)->commitForPath($sub));
        @rmdir($sub);
    }

    /**
     * @dataProvider malformedStampProvider
     */
    public function testMalformedStampFallsThroughRatherThanWinning(string $contents): void
    {
        // Junk must never surface as a commit, and must not block the
        // remaining resolution: with no other signal the result is ''.
        $this->writeStamp($contents);
        $p = $this->provenance(null);

        $this->assertNull($p->commitFromStamp($this->tmpDir));
        $this->assertSame('', $p->commitForPath($this->tmpDir));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedStampProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n"],
            'too short' => ["abc123\n"],
            'non-hex' => ["not-a-sha\n"],
            'branch ref' => ["dev-main\n"],
            'too long' => [str_repeat('a', 41) . "\n"],
            'trailing junk' => ["e26ee58 dirty\n"],
        ];
    }

    public function testMalformedStampStillLetsComposerWin(): void
    {
        // A broken stamp must not shadow a good signal either.
        $this->writeComposerJson('two-inc/magento2');
        $this->writeStamp("not-a-sha\n");
        $p = $this->provenance('0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd');

        $this->assertSame('0aa2194', $p->commitForPath($this->tmpDir));
    }

    public function testStampReadDoesNotThrowOnUnreadablePath(): void
    {
        $this->assertNull($this->provenance(null)->commitFromStamp('/nonexistent/two/module'));
    }

    public function testFallsBackToGitlinkWorktreeWhenNotComposerInstalled(): void
    {
        // composer.json present but the package resolves no reference (null) —
        // e.g. a git-sync/dev checkout — so the .git worktree parse takes over.
        $this->writeComposerJson('two-inc/magento2');
        file_put_contents($this->tmpDir . '/.git', "gitdir: /repo/.git/worktrees/abcdef1234567\n");

        $this->assertSame('abcdef1', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testGitlinkResolvesWithNoComposerJsonAtAll(): void
    {
        // The live gitSync dev install shape: symlinked module directory,
        // no composer package, `.git` a relative gitlink file.
        file_put_contents(
            $this->tmpDir . '/.git',
            "gitdir: ../../.git/worktrees/cd9edfbbdc4d54f1db1c47996b51084edea7c51c\n"
        );

        $this->assertSame('cd9edfb', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testGitlinkFoundOneLevelUpForMonorepoSubpathModule(): void
    {
        // The ABN overlay's gateway module sits at <repo>/plugin; the
        // gitlink is at the checkout root, one level up. Without the
        // parent-dir lookup the overlay row on a gitSync install shows no
        // commit at all (TWO-25197).
        $sub = $this->tmpDir . '/plugin';
        mkdir($sub);
        file_put_contents($this->tmpDir . '/.git', "gitdir: ../../.git/worktrees/abcdef1234567\n");

        $this->assertSame('abcdef1', $this->provenance(null)->commitForPath($sub));
        @rmdir($sub);
    }

    public function testNonHexReferenceIsRejected(): void
    {
        // A path-repo / branch install can carry a non-SHA reference; it must
        // not be shown as a commit — return null so the caller falls back.
        $this->writeComposerJson('two-inc/magento2');

        $this->assertNull($this->provenance('dev-main')->commitFromComposer($this->tmpDir));
    }

    public function testEmptyWhenNeitherComposerNorGitPresent(): void
    {
        $this->assertSame('', $this->provenance(null)->commitForPath($this->tmpDir));
    }

    public function testEmptyForUnknownModuleName(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')->willReturn(null);
        $p = new ProvenanceTestable($registrar);

        $this->assertSame('', $p->commitForModule('Two_NotInstalled'));
    }

    public function testCommitForModuleResolvesRegisteredPath(): void
    {
        $this->writeComposerJson('two-inc/magento2');
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')->willReturn($this->tmpDir);
        $p = new ProvenanceTestable($registrar);
        $p->stubRef = '6f8534ed11ce70d739b3cd910e27d991d508b3f6';

        $this->assertSame('6f8534e', $p->commitForModule('Two_Gateway'));
    }

    public function testPackageNameReadFromParentDirForMonorepoSubpath(): void
    {
        // Monorepo sub-path modules (e.g. the ABN overlay at <repo>/plugin)
        // keep composer.json one level up.
        $sub = $this->tmpDir . '/plugin';
        mkdir($sub);
        $this->writeComposerJson('abn-amro/magento-abn-plugin');
        $p = $this->provenance('0aa21947d6ed57bcf6b35f73a5ed192fc6a9a0dd');

        $this->assertSame('0aa2194', $p->commitFromComposer($sub));
        @rmdir($sub);
    }

    public function testResultIsMemoisedPerPath(): void
    {
        $this->writeComposerJson('two-inc/magento2');
        $p = $this->provenance('6f8534ed11ce70d739b3cd910e27d991d508b3f6');

        $this->assertSame('6f8534e', $p->commitForPath($this->tmpDir));
        $this->assertSame(1, $p->refCalls);
        $p->commitForPath($this->tmpDir);
        $this->assertSame(1, $p->refCalls, 'second lookup should hit the memo');
    }
}

/**
 * Stubs the static Composer registry lookup, which cannot be exercised
 * against a temp directory in a unit test.
 */
class ProvenanceTestable extends Provenance
{
    /** @var string|null */
    public $stubRef = null;

    /** @var int */
    public $refCalls = 0;

    protected function composerReference(string $packageName): ?string
    {
        $this->refCalls++;

        return $this->stubRef;
    }
}
