<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Renders the admin "Version" panel: one row per gateway-stack module
 * (parent payment method, Hyva extension, brand overlays) plus a
 * single assets-freshness row.
 *
 * Per-module signals: composer.json version (authoritative for what's
 * deployed), gitSync worktree commit SHA, source mtime.
 * Panel-level: assets mtime + stale warning when assets are older than
 * the newest module's source.
 *
 * Rows come from the active brand's `<module_label_chain>` declared
 * in its `etc/brand.xml`, resolved at request time via
 * BrandRegistryInterface. Vanilla Two ships ["Payment Method" =>
 * Two_Gateway, "Hyva Extension" => Two_GatewayHyva]; a partner
 * overlay adds its own brand rows. Unregistered entries (e.g.
 * Hyva when not installed) are silently skipped.
 */
class Version extends Field
{

    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/field/version.phtml';

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    private ComponentRegistrar $componentRegistrar;
    private DirectoryList $directoryList;
    private TimezoneInterface $timezone;
    private BrandRegistryInterface $brandRegistry;
    private string $moduleName;

    /**
     * @param ConfigRepository $configRepository
     * @param Context $context
     * @param ComponentRegistrar $componentRegistrar
     * @param DirectoryList $directoryList
     * @param TimezoneInterface $timezone
     * @param BrandRegistryInterface $brandRegistry Source of the per-brand
     *        version-panel row chain. Each brand declares
     *        `<module_label_chain>` in its `etc/brand.xml`; the registry
     *        exposes it via `getModuleLabelChain()`. A partner overlay
     *        adds its own brand rows; vanilla Two ships only
     *        the parent-runtime rows.
     * @param string $moduleName Primary module — used by getVersion() fallback
     *                           and for any caller still expecting a single
     *                           module identity. Defaults to Two_Gateway
     *                           (the canonical runtime). Brand overlays
     *                           do not override this; their identity is
     *                           expressed via brand.xml, not DI.
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Context $context,
        ComponentRegistrar $componentRegistrar,
        DirectoryList $directoryList,
        TimezoneInterface $timezone,
        BrandRegistryInterface $brandRegistry,
        string $moduleName = 'Two_Gateway',
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->componentRegistrar = $componentRegistrar;
        $this->directoryList = $directoryList;
        $this->timezone = $timezone;
        $this->brandRegistry = $brandRegistry;
        $this->moduleName = $moduleName;
        parent::__construct($context, $data);
    }

    /**
     * Active brand's module_label_chain — sourced from its brand.xml
     * at request time via BrandRegistryInterface, which routes through
     * ActiveBrandResolver to the install's single active brand.
     * Replaces the previous constructor-injected `moduleLabels` array
     * and the brand-overlay DI rebind pattern.
     *
     * @return array<string, string>
     */
    private function moduleLabels(): array
    {
        return $this->brandRegistry->getModuleLabelChain();
    }

    /**
     * Primary-module version, used by any non-panel caller.
     *
     * Prefers composer.json on disk (authoritative for deployed code)
     * with a CCD/config.xml fallback for setups where the module path
     * can't be resolved. Returns null when neither source yields a
     * value; the template tolerates null.
     */
    public function getVersion(): ?string
    {
        $modulePath = $this->getModulePathFor($this->moduleName);
        if ($modulePath) {
            $version = $this->readComposerVersion($modulePath);
            if ($version !== null) {
                return $version;
            }
        }
        return $this->configRepository->getExtensionDBVersion();
    }

    /**
     * Rows for the multi-module panel.
     *
     * @return array<int, array{label: string, version: ?string, commit: string, codeAt: string}>
     */
    public function getModules(): array
    {
        $rows = [];
        foreach ($this->moduleLabels() as $label => $moduleName) {
            $path = $this->getModulePathFor($moduleName);
            if (!$path) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'version' => $this->readComposerVersion($path),
                'commit' => $this->extractCommit($path),
                'codeAt' => $this->formatTs($this->getCodeTs($path)),
            ];
        }
        return $rows;
    }

    /**
     * mtime of pub/static/deployed_version.txt — when
     * setup:static-content:deploy last ran. Panel-level, not per-module:
     * assets are compiled once for the whole install.
     */
    public function getAssetsDeployedAt(): string
    {
        return $this->formatTs($this->getAssetsTs());
    }

    /**
     * True when assets are older than the newest enumerated module's
     * source. 5-minute grace tolerates normal init-job step ordering.
     */
    public function isAssetsStale(): bool
    {
        $assetsTs = $this->getAssetsTs();
        if ($assetsTs <= 0) {
            return false;
        }
        $newestCode = 0;
        foreach ($this->moduleLabels() as $moduleName) {
            $path = $this->getModulePathFor($moduleName);
            if (!$path) {
                continue;
            }
            $ts = $this->getCodeTs($path);
            if ($ts > $newestCode) {
                $newestCode = $ts;
            }
        }
        return $newestCode > 0 && ($newestCode - $assetsTs) > 300;
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    public function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    private function getModulePathFor(string $moduleName): ?string
    {
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        return $path ?: null;
    }

    private function readComposerVersion(string $modulePath): ?string
    {
        // Monorepo sub-path modules (e.g. an overlay gateway module at <repo>/plugin)
        // keep their composer.json one level up; check both.
        foreach ([$modulePath, dirname($modulePath)] as $dir) {
            $composer = @file_get_contents($dir . '/composer.json');
            if ($composer === false) {
                continue;
            }
            $data = json_decode($composer, true);
            if (!is_array($data)) {
                continue;
            }
            if (!empty($data['version'])) {
                return (string)$data['version'];
            }
            if ($version = $this->resolvePackageVersion($data, $dir)) {
                return $version;
            }
        }
        return null;
    }

    /**
     * Version for a composer.json that carries no version field (the
     * convention for tag-versioned packages): ask composer's installed
     * registry, then fall back to bumpver.toml for git-checkout deploys
     * (git-sync pods, dev installs) where the package isn't
     * composer-installed.
     *
     * @param array $composerData decoded composer.json
     */
    private function resolvePackageVersion(array $composerData, string $dir): ?string
    {
        $name = $composerData['name'] ?? null;
        if (is_string($name)
            && $name !== ''
            && class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled($name)
        ) {
            $version = \Composer\InstalledVersions::getPrettyVersion($name);
            if ($version !== null && $version !== '') {
                return $version;
            }
        }

        $bumpver = @file_get_contents($dir . '/bumpver.toml');
        if ($bumpver !== false
            && preg_match('/^current_version\s*=\s*"([^"]+)"/m', $bumpver, $m)
        ) {
            return $m[1];
        }

        return null;
    }

    /**
     * 7-char SHA of the gitSync-pulled commit.
     *
     * gitSync v4 writes worktrees at `<root>/.git/worktrees/<sha>/` and
     * names each worktree directory after the SHA it points at. The
     * module's `.git` file (a single line `gitdir: <relpath>`) references
     * that directory. Read it directly — robust whether the module path
     * is a symlink straight to the worktree (older layout) or a real
     * directory whose contents were copied/hardlinked at init (current
     * Magento init job behaviour, which makes the realpath of
     * registration.php contain no worktree segment).
     */
    protected function extractCommit(string $modulePath): string
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

        $gitFile = $modulePath . '/.git';
        if (is_file($gitFile)) {
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
     * mirroring readComposerVersion()), then asks the installed registry for
     * that package's source/dist reference. A path-repo or branch install may
     * carry a non-SHA reference; the hex guard rejects those so the caller
     * falls back to the .git/worktree resolution.
     */
    protected function commitFromComposer(string $modulePath): ?string
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

    private function getCodeTs(string $modulePath): int
    {
        return (int)(@filemtime($modulePath . '/registration.php') ?: 0);
    }

    private function getAssetsTs(): int
    {
        try {
            $marker = $this->directoryList->getPath('static') . '/deployed_version.txt';
        } catch (\Throwable $e) {
            return 0;
        }
        return (int)(@filemtime($marker) ?: 0);
    }

    private function formatTs(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return $this->timezone->date($ts)->format('Y-m-d H:i:s T');
    }
}
