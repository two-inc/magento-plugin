<?php
declare(strict_types=1);

namespace Magento\Framework\App\Config;

/**
 * Minimal Magento\Framework\App\Config\Value stub for unit tests.
 *
 * Mirrors the slice of the real backend-model base class that config
 * backend models rely on in beforeSave(): constructor shape, the
 * protected ScopeConfigInterface in $_config, data accessors for the
 * value/path/scope the Config model sets before save, and
 * getFieldsetDataValue() for sibling fields posted in the same group.
 */
class Value extends \Magento\Framework\DataObject
{
    /** @var ScopeConfigInterface */
    protected $_config;

    public function __construct(
        $context,
        $registry,
        ScopeConfigInterface $config,
        $cacheTypeList,
        $resource = null,
        $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($data);
        $this->_config = $config;
    }

    public function getValue()
    {
        return $this->getData('value');
    }

    public function getPath()
    {
        return $this->getData('path');
    }

    public function getScope()
    {
        return $this->getData('scope');
    }

    public function getScopeId()
    {
        return $this->getData('scope_id');
    }

    public function getFieldsetDataValue($key)
    {
        $data = $this->getData('fieldset_data');
        return is_array($data) && isset($data[$key]) ? $data[$key] : null;
    }

    public function beforeSave()
    {
        return $this;
    }

    /**
     * AbstractModel's public load hook dispatches to the protected one every
     * serialising backend model implements.
     */
    public function afterLoad()
    {
        $this->_afterLoad();
        return $this;
    }

    protected function _afterLoad()
    {
        return $this;
    }

    /**
     * The real base class invalidates the config cache here and returns
     * $this. A backend model's own afterSave() ends by delegating to it, so
     * the stub needs it for that override to be callable at all — which is
     * what lets a test exercise the production afterSave() rather than a
     * reimplementation of it.
     */
    public function afterSave()
    {
        return $this;
    }
}
