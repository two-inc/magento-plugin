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
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * Renders the API-key verification result in Stores Configuration.
 *
 * Reports WHICH kind of failure occurred, not merely that one did. Every
 * non-200 outcome used to render as "API key is not valid", so a store
 * that simply could not reach the API told its admin the key was wrong
 * and sent them off replacing a perfectly good key. The categories come
 * from {@see ApiKeyStatus}; this block only maps them to wording.
 *
 * The upstream response body is never rendered — only the category and,
 * where an HTTP exchange completed, its status code.
 *
 * Verification here is deliberately a LIVE check (ApiKeyStatus::refresh)
 * rather than a cached read: an admin on this page is asking about the key
 * in front of them right now. refresh() also writes its result forward
 * into the shared cache, so a key corrected on this page takes effect at
 * checkout immediately instead of after the cache TTL expires.
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
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @param ConfigRepository $configRepository
     * @param ApiKeyStatus $apiKeyStatus
     * @param BrandRegistryInterface $brandRegistry
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        BrandRegistryInterface $brandRegistry,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->brandRegistry = $brandRegistry;
        parent::__construct($context, $data);
    }

    /**
     * Verification outcome for the stored API key as
     * ['message' => Phrase, 'status' => 'success'|'warning'|'error'],
     * plus 'merchant_id' and 'merchant_short_name' on success only.
     *
     * @return array
     */
    public function getApiKeyStatus(): array
    {
        if (!$this->configRepository->getApiKey()) {
            return [
                'message' => __('API key is missing'),
                'status' => 'warning'
            ];
        }

        $result = $this->apiKeyStatus->refresh();
        $productName = $this->brandRegistry->getProductName();

        switch ($result['status']) {
            case ApiKeyStatus::OK:
                // verify_api_key returns {id, short_name}; surface both so the
                // merchant can confirm at a glance which account the key resolves to.
                $merchant = $result['merchant'] ?? [];
                return [
                    'message' => __('API key is valid'),
                    'status' => 'success',
                    'merchant_id' => isset($merchant['id']) ? (string)$merchant['id'] : '',
                    'merchant_short_name' => isset($merchant['short_name'])
                        ? (string)$merchant['short_name'] : ''
                ];

            case ApiKeyStatus::INVALID_KEY:
                return [
                    'message' => __('This API key was rejected by %1. It may be invalid, expired, or issued for a different environment.', $productName),
                    'status' => 'error'
                ];

            case ApiKeyStatus::SERVICE_ERROR:
                return [
                    'message' => __('%1 could not verify this API key because the service returned an error (HTTP %2). The key itself may be fine, so please try again shortly.', $productName, (string)$result['code']),
                    'status' => 'error'
                ];

            case ApiKeyStatus::UNREACHABLE:
                return [
                    'message' => __('%1 could not be reached to verify this API key. This is a connection problem rather than necessarily a problem with the key. Check that this store can make outbound requests, then try again.', $productName),
                    'status' => 'error'
                ];

            case ApiKeyStatus::MALFORMED_RESPONSE:
                return [
                    'message' => __('%1 returned an unexpected response while verifying this API key, so it could not be confirmed. Please try again shortly.', $productName),
                    'status' => 'error'
                ];

            default:
                return [
                    'message' => __('This API key could not be verified by %1 (HTTP %2).', $productName, (string)$result['code']),
                    'status' => 'error'
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
