<?php
/**
 * Collaborators of Service\RateLimiter: the remote-address reader it buckets
 * callers by, and the webapi exception whose HTTP code the refusal carries.
 */
declare(strict_types=1);

namespace Magento\Framework\HTTP\PhpEnvironment {

    if (!class_exists(RemoteAddress::class, false)) {
        class RemoteAddress
        {
            /**
             * @param bool $ipToLong
             * @return string|int|false
             */
            public function getRemoteAddress($ipToLong = false)
            {
                return false;
            }
        }
    }
}

namespace Magento\Framework\Webapi {

    use Magento\Framework\Phrase;

    if (!class_exists(Exception::class, false)) {
        class Exception extends \Exception
        {
            /** @var int */
            private $httpCode;

            public function __construct(Phrase $phrase, $code = 0, $httpCode = 400)
            {
                parent::__construct((string)$phrase, (int)$code);
                $this->httpCode = (int)$httpCode;
            }

            public function getHttpCode(): int
            {
                return $this->httpCode;
            }
        }
    }
}
