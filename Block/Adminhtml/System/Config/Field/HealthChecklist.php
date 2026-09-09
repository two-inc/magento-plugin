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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * Read-only "install health" panel in Stores Configuration (TWO-25386).
 *
 * Deliberately limited to the checks an admin can act on, rather than
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

    /**
     * @var MerchantMinimumResolver
     */
    private $merchantMinimumResolver;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        RecordProvider $recordProvider,
        SupportedCountriesProvider $supportedCountriesProvider,
        MinimumOrderProvider $minimumOrderProvider,
        MerchantMinimumResolver $merchantMinimumResolver,
        BrandRegistryInterface $brandRegistry,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->recordProvider = $recordProvider;
        $this->supportedCountriesProvider = $supportedCountriesProvider;
        $this->minimumOrderProvider = $minimumOrderProvider;
        $this->merchantMinimumResolver = $merchantMinimumResolver;
        $this->brandRegistry = $brandRegistry;
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
            $this->checkoutVisibilityRow($status),
        ];
    }

    /**
     * Why the payment method is absent from the payment list (ABN-518). Only
     * reasons decidable without a basket are judged; a basket-dependent one is
     * named as a constraint instead.
     *
     * @param array{status: string, code: int|null} $apiKeyStatus
     * @return array{label: string, ok: bool, value: string}
     */
    private function checkoutVisibilityRow(array $apiKeyStatus): array
    {
        $storeId = $this->resolveScopeStoreId();
        $label = (string)__('Payment method at checkout');
        $notShown = (string)__('Not shown at checkout');
        $reason = null;

        if (!$this->configRepository->isActive($storeId)) {
            $reason = (string)__('the payment method is disabled. Check Enable payment method.');
        } elseif ($apiKeyStatus['status'] === ApiKeyStatus::NOT_CONFIGURED) {
            $reason = (string)__('no API key is saved. Check API key.');
        } elseif ($apiKeyStatus['status'] === ApiKeyStatus::INVALID_KEY) {
            $reason = (string)__('the API key was rejected. Check API key and Environment.');
        } elseif ($apiKeyStatus['status'] !== ApiKeyStatus::OK) {
            // ABN-533: only invalid_key and not_configured withhold, so a
            // transient verdict is never reported as the method being hidden.
            return [
                'label' => $label,
                'ok' => false,
                'state' => 'unknown',
                'value' => (string)__('Cannot be checked — the API key could not be verified just now.'),
            ];
        }
        if ($reason === null) {
            try {
                $this->configRepository->getSurchargeType($storeId);
            } catch (LocalizedException) {
                $reason = (string)__('the saved surcharge method is not recognised. Check Surcharge method.');
            }
        }
        if ($reason === null) {
            $countryState = $this->supportedCountriesProvider->getState($storeId);
            if ($countryState === SupportedCountriesProvider::STATE_EMPTY) {
                $reason = (string)__(
                    'no buyer countries are currently enabled for your account. Contact %1 to have them enabled.',
                    $this->brandRegistry->getProviderFullName()
                );
            } elseif ($countryState === SupportedCountriesProvider::STATE_MALFORMED) {
                $reason = (string)__(
                    'the buyer countries on your account could not be read. Contact %1.',
                    $this->brandRegistry->getProviderFullName()
                );
            }
        }
        if ($reason === null && $this->coreCountryGateAllowsNothing($storeId)) {
            $reason = (string)__(
                'Country availability is set to specific countries and Allowed countries is empty.'
            );
        }
        if ($reason !== null) {
            return ['label' => $label, 'ok' => false, 'state' => 'bad', 'value' => $notShown . ' — ' . $reason];
        }

        return ['label' => $label, 'ok' => true, 'state' => 'good', 'value' => $this->offeredValue($storeId)];
    }

    /**
     * "Shown at checkout", plus the minimum-order floors that hide it for a
     * small basket. Both floors bind; they can be denominated differently, so
     * neither can be reduced to the other without an FX rate.
     */
    private function offeredValue(?int $storeId): string
    {
        $shown = (string)__('Shown at checkout');
        $store = $this->_storeManager->getStore($storeId ?? 0);
        $platform = $this->minimumOrderProvider->getMinimum($storeId);
        $merchant = $this->merchantMinimumResolver->resolve(
            $this->brandRegistry->getCode(),
            (string)$store->getBaseCurrencyCode(),
            $platform,
            $storeId
        );
        $floors = self::bindingFloors([$platform, $merchant]);
        if ($floors === []) {
            return $shown;
        }
        if (count($floors) === 1) {
            return $shown . ' — ' . (string)__(
                'hidden for baskets below %1',
                $this->describeFloor($floors[0])
            );
        }

        return $shown . ' — ' . (string)__(
            'hidden for baskets below %1 or %2',
            $this->describeFloor($floors[0]),
            $this->describeFloor($floors[1])
        );
    }

    /**
     * Two floors in the same currency on the same basis are one floor — only
     * the higher binds. Different currencies cannot be reduced without a rate.
     *
     * @param array<int, array{amount: float, currency: string, basis: string}|null> $candidates
     * @return list<array{amount: float, currency: string, basis: string}>
     */
    private static function bindingFloors(array $candidates): array
    {
        $binding = [];
        foreach (array_filter($candidates) as $floor) {
            $key = $floor['currency'] . '|' . $floor['basis'];
            if (!isset($binding[$key]) || $floor['amount'] > $binding[$key]['amount']) {
                $binding[$key] = $floor;
            }
        }

        return array_values($binding);
    }

    /**
     * @param array{amount: float, currency: string, basis: string} $floor
     */
    private function describeFloor(array $floor): string
    {
        return sprintf(
            '%s %s (%s)',
            number_format($floor['amount'], 2, '.', ''),
            $floor['currency'],
            $floor['basis'] === 'net' ? (string)__('excluding tax') : (string)__('including tax')
        );
    }

    /** Core's own allowlist restricted to specific countries with none chosen. */
    private function coreCountryGateAllowsNothing(?int $storeId): bool
    {
        $path = 'payment/' . $this->brandRegistry->getCode() . '/';
        if (!$this->_scopeConfig->isSetFlag($path . 'allowspecific', ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }
        $countries = (string)$this->_scopeConfig->getValue(
            $path . 'specificcountry',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return trim($countries) === '';
    }

    /**
     * The scope the config page is open at, so the row reports the same
     * store's verdict the checkout gate would.
     */
    protected function resolveScopeStoreId(): ?int
    {
        // A stale or hand-edited scope param must degrade to the default
        // scope, never take the whole configuration page down.
        try {
            $store = (string)$this->getRequest()->getParam('store');
            if ($store !== '') {
                return (int)$this->_storeManager->getStore($store)->getId();
            }
            $website = (string)$this->getRequest()->getParam('website');
            if ($website !== '') {
                $default = $this->_storeManager->getWebsite($website)->getDefaultStore();
                return $default ? (int)$default->getId() : null;
            }
        } catch (NoSuchEntityException) {
            return null;
        }

        return null;
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
