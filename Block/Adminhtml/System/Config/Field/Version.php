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
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Renders a deployment-status panel listing every gateway-stack module
 * installed alongside Two_Gateway (parent, Hyva extension, brand
 * overlays) plus a single assets-freshness row.
 *
 * Per-module signals: composer.json version (authoritative for what's
 * deployed), gitSync worktree commit SHA, source mtime.
 * Panel-level: assets mtime + stale warning when assets are older than
 * the newest module's source.
 *
 * The module list comes from the `moduleNames` DI argument. Brand
 * overlays rebind the type and prepend their own module. Unregistered
 * entries (e.g. Hyva when not installed) are silently skipped.
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

    private ?ComponentRegistrar $componentRegistrar;
    private ?DirectoryList $directoryList;
    private ?TimezoneInterface $timezone;
    private string $moduleName;

    /**
     * @var string[]
     */
    private array $moduleNames;

    /**
     * @param ConfigRepository $configRepository
     * @param Context $context
     * @param ComponentRegistrar|null $componentRegistrar
     * @param DirectoryList|null $directoryList
     * @param TimezoneInterface|null $timezone
     * @param string $moduleName Primary module — used by getVersion() fallback
     *                           and for any caller still expecting a single
     *                           module identity.
     * @param string[] $moduleNames Ordered list of modules to enumerate in
     *                              the panel. Defaults to [moduleName,
     *                              'Two_GatewayHyva']. Unregistered entries
     *                              are skipped.
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Context $context,
        ?ComponentRegistrar $componentRegistrar = null,
        ?DirectoryList $directoryList = null,
        ?TimezoneInterface $timezone = null,
        string $moduleName = 'Two_Gateway',
        array $moduleNames = [],
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->componentRegistrar = $componentRegistrar;
        $this->directoryList = $directoryList;
        $this->timezone = $timezone;
        $this->moduleName = $moduleName;
        $this->moduleNames = !empty($moduleNames)
            ? $moduleNames
            : [$moduleName, 'Two_GatewayHyva'];
        parent::__construct($context, $data);
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
     * @return array<int, array{name: string, version: ?string, commit: string, codeAt: string}>
     */
    public function getModules(): array
    {
        $rows = [];
        $seen = [];
        foreach ($this->moduleNames as $name) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $path = $this->getModulePathFor($name);
            if (!$path) {
                continue;
            }
            $rows[] = [
                'name' => $name,
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
        foreach ($this->moduleNames as $name) {
            $path = $this->getModulePathFor($name);
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
        if (!$this->componentRegistrar) {
            return null;
        }
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        return $path ?: null;
    }

    private function readComposerVersion(string $modulePath): ?string
    {
        $composer = @file_get_contents($modulePath . '/composer.json');
        if ($composer === false) {
            return null;
        }
        $data = json_decode($composer, true);
        if (!is_array($data) || empty($data['version'])) {
            return null;
        }
        return (string)$data['version'];
    }

    /**
     * 7-char SHA from the gitSync worktree symlink target. gitSync writes
     * worktrees at `.worktrees/<sha>/` and points the module path symlink
     * (or its parent) at the active worktree.
     */
    private function extractCommit(string $modulePath): string
    {
        $real = @realpath($modulePath . '/registration.php');
        if (!$real) {
            return '';
        }
        if (preg_match('#\.worktrees/([a-f0-9]{7,40})/#', $real, $m)) {
            return substr($m[1], 0, 7);
        }
        return '';
    }

    private function getCodeTs(string $modulePath): int
    {
        return (int)(@filemtime($modulePath . '/registration.php') ?: 0);
    }

    private function getAssetsTs(): int
    {
        if (!$this->directoryList) {
            return 0;
        }
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
        if ($this->timezone) {
            return $this->timezone->date($ts)->format('Y-m-d H:i:s T');
        }
        return gmdate('Y-m-d H:i:s \U\T\C', $ts);
    }
}
