<?php
declare(strict_types=1);

namespace Magento\Framework\App\Config;

interface ScopeConfigInterface
{
    public const SCOPE_TYPE_DEFAULT = 'default';

    public function getValue($path, $scopeType = 'default', $scopeCode = null);

    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
}
