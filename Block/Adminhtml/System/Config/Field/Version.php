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
 * Renders the plugin version + deployment-freshness panel in admin config.
 *
 * Surfaces the signals an operator needs to confirm a deployment fully
 * took: DB-recorded version (setup:upgrade ran), deployed commit SHA
 * (from the gitSync worktree symlink target), source mtime (gitSync
 * pulled), assets mtime (setup:static-content:deploy ran). Warns when
 * assets are older than code — the failure mode where new PHP is live
 * but pub/static/ still serves the previous build.
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
     * Version constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Context $context
     * @param ComponentRegistrar|null $componentRegistrar
     * @param DirectoryList|null $directoryList
     * @param TimezoneInterface|null $timezone
     * @param string $moduleName
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Context $context,
        ?ComponentRegistrar $componentRegistrar = null,
        ?DirectoryList $directoryList = null,
        ?TimezoneInterface $timezone = null,
        string $moduleName = 'Two_Gateway',
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->componentRegistrar = $componentRegistrar;
        $this->directoryList = $directoryList;
        $this->timezone = $timezone;
        $this->moduleName = $moduleName;
        parent::__construct($context, $data);
    }

    /**
     * Resolve the plugin version to display in admin.
     *
     * Prefers the module's `composer.json` (authoritative for what's
     * deployed — gitSync writes it alongside the PHP) so admin reflects
     * the actually-running code. Falls back to the legacy CCD/config.xml
     * read so brand overlays / older setups that rely on
     * `payment/<code>/version` keep rendering.
     *
     * Returns null when neither source yields a value, in which case the
     * template hides the version line rather than crashing on a strict
     * return-type mismatch.
     */
    public function getVersion(): ?string
    {
        $regPath = $this->getModulePath();
        if ($regPath) {
            $composer = @file_get_contents($regPath . '/composer.json');
            if ($composer !== false) {
                $data = json_decode($composer, true);
                if (is_array($data) && !empty($data['version'])) {
                    return (string)$data['version'];
                }
            }
        }
        return $this->configRepository->getExtensionDBVersion();
    }

    /**
     * 7-char SHA of the commit currently mounted by gitSync, extracted
     * from the worktree symlink target (gitSync writes worktrees at
     * .worktrees/<sha>/ and symlinks app/code/... to them).
     */
    public function getDeployedCommit(): string
    {
        $regPath = $this->getModulePath();
        if (!$regPath) {
            return '';
        }
        $real = @realpath($regPath . '/registration.php');
        if (!$real) {
            return '';
        }
        if (preg_match('#\.worktrees/([a-f0-9]{7,40})/#', $real, $m)) {
            return substr($m[1], 0, 7);
        }
        return '';
    }

    /**
     * mtime of registration.php — when gitSync last wrote source files.
     */
    public function getCodeDeployedAt(): string
    {
        $regPath = $this->getModulePath();
        if (!$regPath) {
            return '';
        }
        $ts = @filemtime($regPath . '/registration.php');
        return $ts ? $this->formatTs($ts) : '';
    }

    /**
     * mtime of pub/static/deployed_version.txt — when
     * setup:static-content:deploy last ran.
     */
    public function getAssetsDeployedAt(): string
    {
        $ts = $this->getAssetsTs();
        return $ts ? $this->formatTs($ts) : '';
    }

    /**
     * True if the assets marker is older than the source mtime.
     * 5-minute grace handles normal init-job step ordering.
     */
    public function isAssetsStale(): bool
    {
        $regPath = $this->getModulePath();
        if (!$regPath) {
            return false;
        }
        $codeTs = @filemtime($regPath . '/registration.php') ?: 0;
        $assetsTs = $this->getAssetsTs();
        return $codeTs > 0 && $assetsTs > 0 && ($codeTs - $assetsTs) > 300;
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @inheritDoc
     */
    public function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    private function getModulePath(): ?string
    {
        if (!$this->componentRegistrar) {
            return null;
        }
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $this->moduleName);
        return $path ?: null;
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
        if ($this->timezone) {
            return $this->timezone->date($ts)->format('Y-m-d H:i:s T');
        }
        return gmdate('Y-m-d H:i:s \U\T\C', $ts);
    }
}
