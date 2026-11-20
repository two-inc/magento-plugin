<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Minimal Magento\Framework\FlagManager stub: the flag table's read and
 * write, which the catch-all autoloader's method-less class cannot mock.
 */
if (!class_exists(FlagManager::class, false)) {
    class FlagManager
    {
        /**
         * @param string $code
         * @return mixed
         */
        public function getFlagData($code)
        {
            return null;
        }

        /**
         * @param string $code
         * @param mixed $value
         * @return bool
         */
        public function saveFlag($code, $value)
        {
            return true;
        }
    }
}
