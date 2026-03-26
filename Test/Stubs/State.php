<?php
declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Minimal State stub — constants only.
 */
class State
{
    public const MODE_DEVELOPER = 'developer';
    public const MODE_DEFAULT = 'default';
    public const MODE_PRODUCTION = 'production';

    /** @var string */
    private $mode = self::MODE_DEFAULT;

    public function getMode(): string
    {
        return $this->mode;
    }
}
