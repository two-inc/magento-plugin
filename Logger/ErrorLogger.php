<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Logger;

use Magento\Framework\Serialize\Serializer\Json;
use Monolog\Logger;

/**
 * ErrorLogger logger class
 *
 */
class ErrorLogger extends Logger
{

    /**
     * @var Json
     */
    private $json;

    /**
     * ErrorLogger constructor.
     *
     * @param Json $json
     * @param string $name
     * @param array $handlers
     * @param array $processors
     */
    public function __construct(
        Json $json,
        string $name,
        array $handlers = [],
        array $processors = []
    ) {
        $this->json = $json;
        parent::__construct($name, $handlers, $processors);
    }

    /**
     * Add error data to two Log
     *
     * @param string $type
     * @param mixed $data
     * @param int $level a Monolog level, error unless said otherwise
     */
    public function addLog(string $type, $data, int $level = self::ERROR)
    {
        if (is_array($data) || is_object($data)) {
            $this->addRecord($level, $type . ': ' . $this->json->serialize($data));
        } else {
            $this->addRecord($level, $type . ': ' . $data);
        }
    }
}
