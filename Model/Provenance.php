<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Resolves the commit a deployed Two module was built from.
 *
 * Two deployment shapes exist in the wild and both must resolve:
 *
 *  1. Composer/Packagist install (the 2.0 merchant distribution). The
 *     module lives under vendor/ with no .git of any kind; Composer's
 *     installed registry records the exact source/dist reference —
 *     `Composer\InstalledVersions::getReference('two-inc/magento2')`
 *     returns the full release SHA. Authoritative and layout-independent,
 *     so it is preferred.
 *  2. gitSync dev install. No composer package at all; `app/code/Two/Gateway`
 *     is a symlink to the synced checkout, whose `.git` is a gitlink FILE
 *     (`gitdir: ../../.git/worktrees/<sha>`) — gitSync v4 names each
 *     worktree directory after the SHA it points at.
 *
 * Neither present (a plain source drop) is a legitimate state: every entry
 * point returns '' rather than throwing, so callers degrade to a bare
 * version string and the admin panel still renders.
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
        // Composer-installed deploys (Packagist/dist — the current 2.0
        // distribution model) put the module under vendor/ with NO .git
        // worktree, so the path-based resolution below finds nothing. The
        // installed registry records the exact source/dist commit, which is
        // authoritative and layout-independent — prefer it.
        $fromComposer = $this->commitFromComposer($modulePath);
        if ($fromComposer !== null) {
            return $fromComposer;
        }

        // The gitlink lives at the checkout root. For a top-level module
        // that IS the module directory; for a monorepo sub-path module
        // (the ABN overlay ships its gateway at <repo>/plugin) it is one
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
