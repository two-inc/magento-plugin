<?php
/**
 * Collaborators of Service\RateLimiter: the request whose connection-level
 * peer address it buckets callers by, and the webapi exception whose HTTP
 * code the refusal carries.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Request {

    if (!class_exists(Http::class, false)) {
        class Http
        {
            /** @var array<string,mixed> */
            private $server = [];

            /** @var array<string,mixed> */
            private $headers = [];

            /**
             * @param string|null $name
             * @param mixed $default
             * @return mixed
             */
            public function getServer($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->server;
                }

                return $this->server[$name] ?? $default;
            }

            /**
             * @param string $name
             * @return mixed
             */
            public function getHeader($name, $default = false)
            {
                return $this->headers[$name] ?? $default;
            }

            /** Test seam: stand up the environment a real request would carry. */
            public function setTestEnvironment(array $server, array $headers = []): void
            {
                $this->server = $server;
                $this->headers = $headers;
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
