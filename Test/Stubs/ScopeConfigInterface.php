<?php
declare(strict_types=1);

namespace Magento\Framework\App\Config;

interface ScopeConfigInterface
{
    public const SCOPE_TYPE_DEFAULT = 'default';

    public function getValue($path, $scopeType = 'default', $scopeCode = null);

    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
}

/**
 * Real Magento's ReinitableConfigInterface extends ScopeConfigInterface and
 * adds reinit(). Needed as a real interface (not the bootstrap's empty
 * catch-all stub) so a mock of it can have reinit() configured.
 */
interface ReinitableConfigInterface extends ScopeConfigInterface
{
    public function reinit();
}
