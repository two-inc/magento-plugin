<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Model\Two;

/**
 * Render version field html element in Stores Configuration
 */
class ApiKeyCheck extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/field/apikey.phtml';

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Two
     */
    private $two;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * Version constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $adapter
     * @param Two $two
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $adapter,
        Two $two,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->adapter = $adapter;
        $this->two = $two;
        parent::__construct($context, $data);
    }

    /**
     * Get extension version
     *
     * @return string
     */
    public function getApiKeyStatus(): array
    {
        if (!$this->configRepository->getApiKey()) {
            return [
                'message' => __('API key is missing'),
                'status' => 'warning'
            ];
        }

        $result = $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET');
        $error = $this->two->getErrorFromResponse($result);
        if ($error) {
            return [
                'message' => __('API key is not valid'),
                'status' => 'error',
                'error' => $error
            ];
        } else {
            return [
                'message' => __('API key is valid'),
                'status' => 'success'
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
