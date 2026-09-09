<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Options for the default-payment-term select: the merchant's offerable terms
 * on GET /v1/merchant, behind an empty option that leaves the choice to the
 * checkout's own resolver (ABN-548).
 */
class AvailablePaymentTerms implements OptionSourceInterface
{
    /** @var SettingsProvider */
    private $settingsProvider;

    public function __construct(SettingsProvider $settingsProvider)
    {
        $this->settingsProvider = $settingsProvider;
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Automatic')]];
        foreach ($this->settingsProvider->getAvailableTerms() as $days) {
            $options[] = ['value' => $days, 'label' => __('%1 days', $days)];
        }
        return $options;
    }
}
