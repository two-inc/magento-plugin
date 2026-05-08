<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Block\Adminhtml\System\Config\Button;

use Exception;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use ABN\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Error log check button class
 *
 */
class ErrorCheck extends Field
{

    /**
     * @var string
     */
    protected $_template = 'ABN_Gateway::system/config/button/error.phtml';

    /**
     * @var LogRepository
     */
    private $logger;

    /**
     * Credentials constructor.
     *
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        LogRepository $logger,
        array $data = []
    ) {
        $this->logger = $logger;
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     *
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     *
     * @return string
     */
    public function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * @return string
     */
    public function getErrorCheckUrl()
    {
        return $this->getUrl('abn/log/error');
    }

    /**
     * @return mixed
     */
    public function getButtonHtml()
    {
        $buttonData = ['id' => 'two-button_error', 'label' => __('Check last 100 error log records')];
        try {
            $button = $this->getLayout()->createBlock(
                Button::class
            )->setData($buttonData);
            return $button->toHtml();
        } catch (Exception $e) {
            $this->logger->addLog('LocalizedException', $e->getMessage());
            return false;
        }
    }
}
