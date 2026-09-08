<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Comment;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\FieldGate\EndOfMonth;

/**
 * Names End-of-Month semantics in the custom-days help text only where that type is stored (Q46).
 */
class PaymentTermsCustomDays implements CommentInterface
{
    private $scopeConfig;

    private $request;

    private $storeManager;

    private $brandRegistry;

    private $endOfMonth;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        BrandRegistryInterface $brandRegistry,
        EndOfMonth $endOfMonth
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->brandRegistry = $brandRegistry;
        $this->endOfMonth = $endOfMonth;
    }

    /**
     * @inheritDoc
     */
    public function getCommentText($elementValue)
    {
        if ($this->endOfMonth->isConfigured($this->storedType())) {
            return (string)__(
                'Optional. Enter a custom number of days past the end of the month'
                . ' to offer alongside the selected terms above.'
            );
        }

        return (string)__(
            'Optional. Enter a custom number of days to offer alongside the selected terms above.'
        );
    }

    /**
     * Null where the scope params name no resolvable store or website.
     */
    private function storedType()
    {
        $path = 'payment/' . $this->brandRegistry->getCode() . '/payment_terms_type';
        try {
            if ($store = $this->request->getParam('store')) {
                return $this->scopeConfig->getValue(
                    $path,
                    ScopeInterface::SCOPE_STORE,
                    (int)$this->storeManager->getStore($store)->getId()
                );
            }
            if ($website = $this->request->getParam('website')) {
                return $this->scopeConfig->getValue(
                    $path,
                    ScopeInterface::SCOPE_WEBSITE,
                    (int)$this->storeManager->getWebsite($website)->getId()
                );
            }
        } catch (\Exception $e) {
            return null;
        }

        return $this->scopeConfig->getValue($path);
    }
}
