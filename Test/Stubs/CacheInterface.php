<?php
/**
 * Stub of Magento\Framework\App\CacheInterface with the real method
 * signatures, so tests can configure mocks of load()/save() (the
 * catch-all bootstrap stub is method-less and unmockable).
 */
declare(strict_types=1);

namespace Magento\Framework\App;

interface CacheInterface
{
    /**
     * @param string $identifier
     * @return string|false
     */
    public function load($identifier);

    /**
     * @param string $data
     * @param string $identifier
     * @param array $tags
     * @param int|null $lifeTime
     * @return bool
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null);

    /**
     * @param string $identifier
     * @return bool
     */
    public function remove($identifier);

    /**
     * @param array $tags
     * @return bool
     */
    public function clean($tags = []);
}
