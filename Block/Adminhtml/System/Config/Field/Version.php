<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Render version field html element in Stores Configuration
 */
class Version extends Field
{

    /**
     * @var string
     */
    protected $_template = 'ABN_Gateway::system/config/field/version.phtml';

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * Version constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        parent::__construct($context, $data);
    }

    /**
     * Get extension version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->configRepository->getExtensionDBVersion();
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @inheritDoc
     */
    public function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
