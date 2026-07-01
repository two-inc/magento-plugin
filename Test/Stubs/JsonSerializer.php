<?php
/**
 * Faithful stub of Magento\Framework\Serialize\Serializer\Json - the
 * real class is a thin json_encode/json_decode wrapper, and tests
 * instantiate it directly (no mock), so the behaviour must be real.
 */
declare(strict_types=1);

namespace Magento\Framework\Serialize\Serializer;

class Json
{
    /**
     * @param mixed $data
     * @return string
     */
    public function serialize($data)
    {
        $result = json_encode($data);
        if ($result === false) {
            throw new \InvalidArgumentException('Unable to serialize value.');
        }
        return $result;
    }

    /**
     * @param string $string
     * @return mixed
     */
    public function unserialize($string)
    {
        $result = json_decode((string)$string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Unable to unserialize value.');
        }
        return $result;
    }
}
