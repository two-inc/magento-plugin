<?php
declare(strict_types=1);

namespace Magento\Framework\Exception;

/**
 * Minimal InputException stub for unit tests. Mirrors the real Magento
 * inheritance (extends LocalizedException → Exception) so it's throwable
 * and `instanceof LocalizedException` holds where downstream code relies
 * on that. Magento's webapi serializer maps InputException to HTTP 400.
 */
class InputException extends LocalizedException
{
}
