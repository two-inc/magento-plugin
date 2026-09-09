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
     * When the merchant profile last refreshed, and whether the scheduled
     * refresh is running. A read that had to stand in for the cron, and one
     * that could not resolve a record at all, both leave a mark a scheduled
     * run clears — the record's own stamp cannot answer that, because a
     * stand-in moves it (ABN-519).
     *
     * @return array{label: string, ok: bool, value: string}
     */
    private function merchantProfileRow(string $mode): array
    {
        $status = $this->recordProvider->status($mode, $this->configRepository->getApiKey());
        $label = (string)__('Merchant profile');
        $fetchedAt = $status['fetched_at'];
        $absentAt = $status['absent_on_read_at'];
        // A stamp newer than the mark means the miss has since been answered.
        // Two intervals, not one, so ordinary cron jitter is not a diagnosis.
        if ($absentAt !== null
            && time() - $absentAt >= 2 * RecordProvider::CRON_INTERVAL
            && ($fetchedAt === null || $fetchedAt < $absentAt)
        ) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => (string)__(
                    'Missing when read at %1 — the hourly refresh appears not to be running',
                    $this->formatTimestamp($absentAt)
                ),
            ];
        }
        // Two ways the schedule shows as dead against a record that is present:
        // a stand-in mark it never cleared, and a stamp older than one refresh
        // plus a tick's grace. The stamp alone cannot answer it, because a
        // stand-in moves the stamp; the mark alone cannot, because a store with
        // no traffic never stands in.
        $stoodInAt = $status['stood_in_at'];
        $notRunning = ($stoodInAt !== null && time() - $stoodInAt >= 2 * RecordProvider::CRON_INTERVAL)
            || ($fetchedAt !== null
                && time() - $fetchedAt >= RecordProvider::MAX_AGE + 2 * RecordProvider::CRON_INTERVAL);
        if ($fetchedAt !== null && $notRunning) {
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
