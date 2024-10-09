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
use ABN\Gateway\Service\Api\Adapter;
use ABN\Gateway\Model\Two;

/**
 * Render version field html element in Stores Configuration
 */
class ApiKeyCheck extends Field
{

    /**
     * @var string
     */
    protected $_template = 'ABN_Gateway::system/config/field/apikey.phtml';

    /**
     * @var ConfigRepository
     */
    private $_configRepository;

    /**
     * @var Two
     */
    private $_two;

    /**
     * @var Adapter
     */
    private $_adapter;

    /**
     * Version constructor.
     *
     * @param Context $context
    * @param Adapter $adapter
     * @param ConfigRepository $configRepository
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $adapter,
        Two $two,
        Context $context,
        array $data = []
    ) {
        $this->_configRepository = $configRepository;
        $this->_adapter = $adapter;
        $this->_two = $two;
        parent::__construct($context, $data);
    }

    /**
     * Get extension version
     *
     * @return string
     */
    public function getApiKeyStatus(): array
    {
        $short_name = $this->_configRepository->getMerchantShortName();
        if ($short_name && $this->_configRepository->getApiKey()) {
            $result = $this->_adapter->execute('/v1/merchant/' . $short_name, [], 'GET');
            $error = $this->_two->getErrorFromResponse($result);
            if ($error) {
                return [
                    'message' => __('Credentials are invalid'),
                    'status' => 'error',
                    'error' => $error
                ];
            } else {
                if ($result['short_name'] != $short_name) {
                    return [
                        'message' => __('Username should be set to %1', $result['short_name']),
                        'status' => 'warning',
                    ];
                }
                return [
                    'message' => __('Credentials are valid'),
                    'status' => 'success'
                ];
            }
        } else {
            return [
                'message' => __('Credentials are missing'),
                'status' => 'warning'
            ];
        }
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
