<?php
declare(strict_types=1);

namespace Magento\Store\Model;

interface ScopeInterface
{
    public const SCOPE_STORE = 'store';
    public const SCOPE_STORES = 'stores';
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_WEBSITES = 'websites';
    public const SCOPE_GROUP = 'group';
}
