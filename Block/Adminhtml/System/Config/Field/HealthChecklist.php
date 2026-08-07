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
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * Read-only "install health" panel in Stores Configuration (TWO-25386,
 * ported from prestashop-plugin's renderTwoPluginHealthChecklist).
 *
 * Deliberately mirrors the same three checks prestashop-plugin ships —
 * API key, environment, SSL verification — rather than inventing new ones
 * (e.g. webhook reachability, PHP extensions) that plugin's checklist does
 * not have either.
 *
 * Uses the cached ApiKeyStatus::getStatus() rather than a live refresh():
 * the neighbouring "API key check" field (ApiKeyCheck) already performs a
 * live verification on this same page render, so a second live HTTP call
 * here would be redundant.
 */
class HealthChecklist extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/field/health-checklist.phtml';

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    public function __construct(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->apiKeyStatus = $apiKeyStatus;
        parent::__construct($context, $data);
    }

    /**
     * Checklist rows: label, ok (bool), value (display string).
     *
     * @return array<int, array{label: string, ok: bool, value: string}>
     */
    public function getChecklistRows(): array
    {
        $status = $this->apiKeyStatus->getStatus();
        $apiKeyOk = $status['status'] === ApiKeyStatus::OK;

        $sslDisabled = $this->configRepository->isSslVerificationDisabled();
        $mode = $this->configRepository->getMode();

        return [
            [
                'label' => (string)__('API key'),
                'ok' => $apiKeyOk,
                'value' => $apiKeyOk ? (string)__('Verified') : (string)__('Not verified'),
            ],
            [
                'label' => (string)__('Environment'),
                'ok' => true,
                'value' => $mode !== '' ? strtoupper($mode) : (string)__('Not set'),
            ],
            [
                'label' => (string)__('SSL verification'),
                'ok' => !$sslDisabled,
                'value' => $sslDisabled ? (string)__('Disabled') : (string)__('Enabled'),
            ],
        ];
    }

    /**
     * True when the environment is production and SSL verification is
     * disabled — the one combination worth a loud warning, mirroring
     * prestashop-plugin's equivalent banner.
     */
    public function isProductionWithSslDisabled(): bool
    {
        return $this->configRepository->getMode() === 'production'
            && $this->configRepository->isSslVerificationDisabled();
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
