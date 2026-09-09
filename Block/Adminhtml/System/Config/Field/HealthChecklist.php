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
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * Read-only "install health" panel in Stores Configuration (TWO-25386).
 *
 * Deliberately limited to the checks an admin can act on — API key,
 * environment, SSL verification, merchant profile refresh, and whether
 * the payment method is currently offered at checkout — rather than
 * inventing new ones (e.g. webhook reachability, PHP extensions).
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

    /**
     * @var SupportedCountriesProvider
     */
    private $supportedCountriesProvider;

    /**
     * @var MinimumOrderProvider
     */
    private $minimumOrderProvider;

    public function __construct(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        RecordProvider $recordProvider,
        SupportedCountriesProvider $supportedCountriesProvider,
        MinimumOrderProvider $minimumOrderProvider,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->recordProvider = $recordProvider;
        $this->supportedCountriesProvider = $supportedCountriesProvider;
        $this->minimumOrderProvider = $minimumOrderProvider;
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
            $this->checkoutVisibilityRow(),
        ];
    }

    /**
     * Why the payment method is absent from the payment list (ABN-518). Only
     * reasons decidable without a basket are judged; a basket-dependent one is
     * named as a constraint instead.
     *
     * @return array{label: string, ok: bool, value: string}
     */
    private function checkoutVisibilityRow(): array
    {
        $label = (string)__('Payment method at checkout');
        $notShown = (string)__('Not shown at checkout');

        if (!$this->configRepository->isActive()) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => $notShown . ' — '
                    . (string)__('the payment method is disabled. Check Enable payment method.'),
            ];
        }
        $status = $this->apiKeyStatus->getStatus();
        if ($status['status'] === ApiKeyStatus::NOT_CONFIGURED) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => $notShown . ' — ' . (string)__('no API key is saved. Check API key.'),
            ];
        }
        if ($status['status'] === ApiKeyStatus::INVALID_KEY) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => $notShown . ' — '
                    . (string)__('the API key was rejected. Check API key and Environment.'),
            ];
        }
        // ABN-533 will stop transient verdicts withholding at all, so this row
        // must not report one as the method being hidden.
        if ($status['status'] !== ApiKeyStatus::OK) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => (string)__('Cannot be checked — the API key could not be verified just now.'),
            ];
        }
        try {
            $this->configRepository->getSurchargeType();
        } catch (LocalizedException) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => $notShown . ' — '
                    . (string)__('the saved surcharge method is not recognised. Check Surcharge method.'),
            ];
        }
        $countries = $this->supportedCountriesProvider->getAllowedCountries();
        if ($countries !== null && $countries === []) {
            return [
                'label' => $label,
                'ok' => false,
                'value' => $notShown . ' — '
                    . (string)__('your account allows no buyer countries. Contact us to have them enabled.'),
            ];
        }

        $shown = (string)__('Shown at checkout');
        $minimum = $this->minimumOrderProvider->getMinimum();
        if ($minimum !== null) {
            return [
                'label' => $label,
                'ok' => true,
                'value' => $shown . ' — ' . (string)__(
                    'hidden for baskets below %1 %2 (%3)',
                    number_format($minimum['amount'], 2, '.', ''),
                    $minimum['currency'],
                    $minimum['basis']
                ),
            ];
        }

        return ['label' => $label, 'ok' => true, 'value' => $shown];
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
        // Three ways a schedule that is not running shows up against a record
        // that is present. Its own run stamp going stale is the direct one. A
        // stand-in mark it never cleared covers the window before that stamp
        // exists at all. An overdue success stamp covers a store with no
        // traffic, which never stands in — but only while no run stamp says
        // otherwise, since a cron that runs and cannot reach the API moves the
        // run stamp and not the success stamp.
        $grace = 2 * RecordProvider::CRON_INTERVAL;
        $stoodInAt = $status['stood_in_at'];
        $scheduledAt = $status['scheduled_at'];
        $notRunning = ($scheduledAt !== null && time() - $scheduledAt >= $grace)
            || ($stoodInAt !== null && time() - $stoodInAt >= $grace)
            || ($scheduledAt === null
                && $fetchedAt !== null
                && time() - $fetchedAt >= RecordProvider::MAX_AGE + $grace);
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
