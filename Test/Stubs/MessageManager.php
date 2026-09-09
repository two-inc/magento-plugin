<?php
declare(strict_types=1);

namespace Magento\Framework\Message;

/**
 * Minimal Magento\Framework\Message\ManagerInterface stub for unit tests.
 *
 * Only the admin-facing adders the plugin calls: the catch-all autoloader
 * would produce a method-less interface, which PHPUnit cannot configure or
 * assert calls against.
 */
if (!interface_exists(ManagerInterface::class, false)) {
    interface ManagerInterface
    {
        public function addErrorMessage($message, $group = null);

        public function addWarningMessage($message, $group = null);

        public function addNoticeMessage($message, $group = null);

        public function addSuccessMessage($message, $group = null);
    }
}
