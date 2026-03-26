<?php
declare(strict_types=1);

namespace Magento\Framework\Exception;

use Magento\Framework\Phrase;

/**
 * Minimal LocalizedException stub for unit tests.
 *
 * Must extend \Exception so it's throwable. Constructor accepts a Phrase
 * and passes the rendered string to the parent Exception.
 */
class LocalizedException extends \Exception
{
    /** @var Phrase */
    private $phrase;

    public function __construct(Phrase $phrase, ?\Exception $cause = null, int $code = 0)
    {
        $this->phrase = $phrase;
        parent::__construct($phrase->render(), $code, $cause);
    }

    public function getLogMessage(): string
    {
        return $this->phrase->render();
    }
}
