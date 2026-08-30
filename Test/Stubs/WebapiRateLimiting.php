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

namespace Magento\Framework\HTTP\PhpEnvironment {

    use Magento\Framework\App\Request\Http as HttpRequest;

    if (!class_exists(RemoteAddress::class, false)) {
        /**
         * Hand-port of Magento\Framework\HTTP\PhpEnvironment\RemoteAddress as
         * of 2.4.7 — re-check getRemoteAddress()/trusted-proxy filtering
         * against the framework on a Magento upgrade, it can drift silently.
         *
         * Typed against the concrete request rather than RequestInterface,
         * which the bootstrap's catch-all would generate as an interface the
         * request stub does not implement.
         */
        class RemoteAddress
        {
            /** @var HttpRequest */
            private $request;

            /** @var string[] */
            private $alternativeHeaders;

            /** @var string[]|null */
            private $trustedProxies;

            /** @var string|false|null */
            private $remoteAddress;

            public function __construct(
                HttpRequest $httpRequest,
                array $alternativeHeaders = [],
                ?array $trustedProxies = null
            ) {
                $this->request = $httpRequest;
                $this->alternativeHeaders = $alternativeHeaders;
                $this->trustedProxies = $trustedProxies;
            }

            /**
             * @return string|null
             */
            private function readAddress()
            {
                $remoteAddress = null;
                foreach ($this->alternativeHeaders as $var) {
                    if ($this->request->getServer($var, false)) {
                        $remoteAddress = $this->request->getServer($var);
                        break;
                    }
                }
                if (!$remoteAddress) {
                    $remoteAddress = $this->request->getServer('REMOTE_ADDR');
                }

                return $remoteAddress;
            }

            /**
             * @return string|null
             */
            private function filterAddress(string $remoteAddress)
            {
                $ipList = strpos($remoteAddress, ',') !== false
                    ? explode(',', $remoteAddress)
                    : [$remoteAddress];
                $ipList = array_filter(
                    $ipList,
                    static fn(string $ip) => filter_var(trim($ip), FILTER_VALIDATE_IP)
                );
                if ($this->trustedProxies !== null) {
                    $ipList = array_filter(
                        $ipList,
                        fn(string $ip) => !in_array(trim($ip), $this->trustedProxies, true)
                    );
                    $remoteAddress = empty($ipList) ? '' : trim((string)array_pop($ipList));
                } else {
                    $remoteAddress = trim((string)reset($ipList));
                }

                return $remoteAddress ?: null;
            }

            /**
             * @return string|false
             */
            public function getRemoteAddress(bool $ipToLong = false)
            {
                if ($this->remoteAddress !== null) {
                    return $ipToLong ? ip2long($this->remoteAddress) : $this->remoteAddress;
                }

                $remoteAddress = $this->readAddress();
                if (!$remoteAddress) {
                    $this->remoteAddress = false;

                    return false;
                }
                $remoteAddress = $this->filterAddress((string)$remoteAddress);
                if (!$remoteAddress) {
                    $this->remoteAddress = false;

                    return false;
                }
                $this->remoteAddress = $remoteAddress;

                return $ipToLong ? ip2long($this->remoteAddress) : $this->remoteAddress;
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
