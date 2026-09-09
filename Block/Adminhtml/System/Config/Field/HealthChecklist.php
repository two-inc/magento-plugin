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
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * Read-only "install health" panel in Stores Configuration (TWO-25386).
 *
 * Deliberately limited to three checks — API key, environment, SSL
 * verification — rather than inventing new ones (e.g. webhook
 * reachability, PHP extensions).
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

    /**
     * @var RecordProvider
     */
    private $recordProvider;

    public function __construct(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        RecordProvider $recordProvider,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->recordProvider = $recordProvider;
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
            $this->merchantProfileRow($mode),
        ];
    }

    /**
     * When the merchant profile last refreshed. An absent-on-read mark the cron
     * has had a run to clear, and a stamp older than STALE_AFTER, both say the
     * cron is not running; neither withholds anything.
     *
     * @return array{label: string, ok: bool, value: string}
     */
    private function merchantProfileRow(string $mode): array
    {
        $status = $this->recordProvider->status($mode, $this->configRepository->getApiKey());
        $label = (string)__('Merchant profile');
        $absentAt = $status['absent_on_read_at'];
        if ($absentAt !== null && time() - $absentAt >= RecordProvider::CRON_INTERVAL) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => (string)__(
                    'Missing when read at %1 — the hourly refresh appears not to be running',
                    $this->formatTimestamp($absentAt)
                ),
            ];
        }
        $fetchedAt = $status['fetched_at'];
        if ($fetchedAt !== null && time() - $fetchedAt >= RecordProvider::STALE_AFTER) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => (string)__(
                    'Refreshed %1 — the hourly refresh appears not to be running',
                    $this->formatTimestamp($fetchedAt)
                ),
            ];
        }
        if ($fetchedAt !== null) {
            return [
                'label' => $label,
                'ok' => true,
                'value' => (string)__('Refreshed %1', $this->formatTimestamp($fetchedAt)),
            ];
        }

        return ['label' => $label, 'ok' => false, 'value' => (string)__('Never refreshed')];
    }

    protected function formatTimestamp(int $timestamp): string
    {
        return $this->_localeDate->formatDateTime((new \DateTime())->setTimestamp($timestamp));
    }

    /**
     * True when the environment is production and SSL verification is
     * disabled — the one combination worth a loud warning.
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
