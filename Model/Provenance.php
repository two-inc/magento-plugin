<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Resolves the commit a deployed Two module is running.
 *
 * Three deployment shapes exist in the wild and all three must resolve:
 *
 *  1. gitSync dev install. No composer package at all; `app/code/Two/Gateway`
 *     is a symlink to the synced checkout, whose `.git` is a gitlink FILE
 *     (`gitdir: ../../.git/worktrees/<sha>`) — gitSync v4 names each
 *     worktree directory after the SHA it points at. The `gitdir:` target is
 *     typically DANGLING inside the container, so the SHA is string-parsed
 *     out of the gitlink; never shell out to `git`.
 *  2. Composer/Packagist install (the 2.0 merchant distribution). The
 *     module lives under vendor/ with no .git of any kind; Composer's
 *     installed registry records the exact source/dist reference —
 *     `Composer\InstalledVersions::getReference('two-inc/magento2')`
 *     returns the full release SHA.
 *  3. Zip drop. A branding overlay may ship as a release-asset / GCS zip that is
 *     unpacked straight into `app/code`, carrying neither a `.git` nor a
 *     Composer registry entry — so neither signal above exists. `make
 *     archive` stamps a `.two-deployed-commit` file into the zip at build
 *     time to close that gap.
 *
 * Resolution order is `.git` gitlink → Composer reference →
 * `.two-deployed-commit` stamp, one org-wide order shared by all six Two
 * plugin artifacts (Magento, Magento overlay, WooCommerce, WooCommerce overlay,
 * PrestaShop, OpenCart). The order is freshness-ranked, not
 * confidence-ranked: the gitlink is the only signal that reflects what is
 * checked out *right now*, the Composer reference is recorded once at
 * install time, and the build stamp is frozen at build time and so is the
 * most likely of the three to be stale. Whichever is freshest and present
 * wins; a malformed signal falls through to the next rather than winning.
 *
 * None present (a plain source drop with no stamp) is a legitimate state:
 * every entry point returns '' rather than throwing, so callers degrade to a
 * bare version string and the admin panel still renders.
 *
 * Note the SHA is repo-wide, not module-unique: for a repo that ships two
 * modules from sub-paths (an overlay package's `plugin/` and `hyva/`) both
 * legitimately report the same commit.
 *
 * This class is the single owner of that logic. The admin Version block and
 * the config Repository (which stamps the SHA onto the `client_v` telemetry
 * parameter) both consume it; neither duplicates the parsing.
 */
class Provenance
{
    private ComponentRegistrar $componentRegistrar;

    /**
     * Per-request memo, keyed by module path. Resolution touches the
     * filesystem and this is called on every outbound API URL build.
     *
     * @var array<string, string>
     */
    private array $commitCache = [];

    public function __construct(ComponentRegistrar $componentRegistrar)
    {
        $this->componentRegistrar = $componentRegistrar;
    }

    /**
     * 7-char commit SHA for a registered module, or '' when it cannot be
     * determined (module not registered, or neither provenance signal
     * present).
     */
    public function commitForModule(string $moduleName): string
    {
        try {
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        } catch (\Throwable $e) {
            return '';
        }
        if (!$path) {
            return '';
        }
        return $this->commitForPath($path);
    }

    /**
     * 7-char commit SHA for a module directory, or '' when undeterminable.
     *
     * Never throws: provenance is diagnostic metadata, and a broken admin
     * page or a failed API call would be a wildly disproportionate cost for
     * an unreadable dotfile.
     */
    public function commitForPath(string $modulePath): string
    {
        if (isset($this->commitCache[$modulePath])) {
            return $this->commitCache[$modulePath];
        }
        try {
            $commit = $this->resolve($modulePath);
        } catch (\Throwable $e) {
            $commit = '';
        }
        $this->commitCache[$modulePath] = $commit;
        return $commit;
    }

    private function resolve(string $modulePath): string
    {
        // FIRST: the gitlink. It is the only signal that tracks what is
        // checked out right now — a gitSync pull moves it on every deploy,
        // where the Composer reference is fixed at install time and the
        // build stamp at build time.
        //
        // The gitlink lives at the checkout root. For a top-level module
        // that IS the module directory; for a monorepo sub-path module
        // (an overlay package ships its gateway at <repo>/plugin) it is one
        // level up — same two-place lookup composer.json needs.
        foreach ([$modulePath, dirname($modulePath)] as $dir) {
            $gitFile = $dir . '/.git';
            if (!is_file($gitFile)) {
                continue;
            }
            // .git is always `gitdir: <relpath>\n`; cap the read defensively
            // and trim before anchoring the regex to end-of-string so a
            // worktrees/<sha> segment elsewhere in the path can't shadow
            // the real SHA at the tail.
            $content = @file_get_contents($gitFile, false, null, 0, 1024);
            if ($content !== false
                && preg_match('#worktrees/([a-f0-9]{7,40})/?$#', trim($content), $m)
            ) {
                return substr($m[1], 0, 7);
            }
        }

        // SECOND: Composer-installed deploys (Packagist/dist — the current
        // 2.0 merchant distribution model) put the module under vendor/ with
        // NO .git worktree. The installed registry records the exact
        // source/dist commit, recorded once at install time.
        $fromComposer = $this->commitFromComposer($modulePath);
        if ($fromComposer !== null) {
            return $fromComposer;
        }

        // THIRD: the build stamp. A zip-dropped module (an overlay package's
        // GCS/release-asset zips) has neither of the above; `make archive`
        // writes the build commit into the zip. Frozen at build time, hence
        // last of the three.
        $fromStamp = $this->commitFromStamp($modulePath);
        if ($fromStamp !== null) {
            return $fromStamp;
        }

        // Legacy fallback: module path is a symlink through the worktree.
        $real = @realpath($modulePath . '/registration.php');
        if ($real && preg_match('#\.worktrees/([a-f0-9]{7,40})/#', $real, $m)) {
            return substr($m[1], 0, 7);
        }
        return '';
    }

    /**
     * 7-char commit SHA from Composer's installed registry, or null when the
     * module isn't composer-installed or carries no hex source reference.
     *
     * Reads the package name from composer.json (checking the module dir and
     * one level up — monorepo sub-path modules keep composer.json a level up,
     * mirroring the version lookup in the admin Version block), then asks the
     * installed registry for that package's source/dist reference. A path-repo
     * or branch install may carry a non-SHA reference; the hex guard rejects
     * those so the caller falls back to the .git/worktree resolution.
     */
    public function commitFromComposer(string $modulePath): ?string
    {
        foreach ([$modulePath, dirname($modulePath)] as $dir) {
            $composer = @file_get_contents($dir . '/composer.json');
            if ($composer === false) {
                continue;
            }
            $data = json_decode($composer, true);
            $name = is_array($data) ? ($data['name'] ?? null) : null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $ref = $this->composerReference($name);
            if (is_string($ref) && preg_match('/^[a-f0-9]{7,40}$/', $ref)) {
                return substr($ref, 0, 7);
            }
        }
        return null;
    }

    /**
     * 7-char commit SHA from the `.two-deployed-commit` build stamp, or null
     * when absent, unreadable or malformed.
     *
     * `make archive` writes the build commit into the release zip, which is
     * how a zip-dropped module (an overlay package's GCS zips) reports its
     * provenance at all — it carries neither a `.git` nor a Composer
     * registry entry. Checks the module dir and one level up, mirroring the
     * gitlink and composer.json lookups: a sub-path module's stamp is written
     * at the repo root the archive was taken from.
     *
     * Never throws, and a malformed or empty stamp returns null so the
     * caller falls THROUGH to the remaining fallback rather than surfacing
     * junk as a commit.
     */
    public function commitFromStamp(string $modulePath): ?string
    {
        foreach ([$modulePath, dirname($modulePath)] as $dir) {
            // Cap the read: a legitimate stamp is one short hex line, and
            // this runs on every outbound API URL build.
            $raw = @file_get_contents($dir . '/.two-deployed-commit', false, null, 0, 128);
            if ($raw === false) {
                continue;
            }
            $candidate = trim($raw);
            if (preg_match('/^[a-f0-9]{7,40}$/i', $candidate)) {
                return strtolower(substr($candidate, 0, 7));
            }
        }
        return null;
    }

    /**
     * The installed package's source/dist reference (commit SHA), or null.
     * Wraps the static Composer registry as an override seam for testing.
     */
    protected function composerReference(string $packageName): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)
            || !\Composer\InstalledVersions::isInstalled($packageName)
        ) {
            return null;
        }
        return \Composer\InstalledVersions::getReference($packageName);
    }
}
