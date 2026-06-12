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
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders the merchant minimum-order-value field with the store base
 * currency in the label ("Minimum Order Value, EUR") - the value is
 * interpreted in that currency, so the label must say which one the
 * current config scope resolves to.
 */
class MinimumOrderValue extends Field
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        $currency = $this->resolveScopeBaseCurrency();
        if ($currency !== '') {
            $element->setLabel(__('Minimum Order Value, %1', $currency));
        }
        return parent::render($element);
    }

    /**
     * Base currency of the store the admin config-scope selector points
     * at (store param, website default store, or the global default
     * store).
     *
     * Counterparts: Comment\MerchantMinimumOrder::resolveScopeStore() and
     * Backend\MerchantMinimumOrder::resolveScopeStore() resolve the same
     * scope for the comment text and the save-time validation. Keep the
     * three in lockstep or the label, the displayed floor, and the
     * validated floor can disagree on the currency.
     */
    private function resolveScopeBaseCurrency(): string
    {
        try {
            if ($storeCode = $this->getRequest()->getParam('store')) {
                return (string)$this->storeManager->getStore($storeCode)->getBaseCurrencyCode();
            }
            if ($websiteCode = $this->getRequest()->getParam('website')) {
                $website = $this->storeManager->getWebsite($websiteCode);
                $store = $this->storeManager->getStore($website->getDefaultGroup()->getDefaultStoreId());
                return (string)$store->getBaseCurrencyCode();
            }
            return (string)$this->storeManager->getStore()->getBaseCurrencyCode();
        } catch (\Exception $e) {
            return '';
        }
    }
}
