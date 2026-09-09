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
use Magento\Framework\Escaper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\FieldGate\EndOfMonth;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Names End-of-Month semantics in the custom-days help text only where that type is stored (ABN-495).
 */
class PaymentTermsCustomDays implements CommentInterface
{
    private $scopeConfig;

    private $request;

    private $storeManager;

    private $brandRegistry;

    private $endOfMonth;

    private $escaper;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        BrandRegistryInterface $brandRegistry,
        EndOfMonth $endOfMonth,
        Escaper $escaper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->brandRegistry = $brandRegistry;
        $this->endOfMonth = $endOfMonth;
        $this->escaper = $escaper;
    }

    /**
     * @inheritDoc
     */
    public function getCommentText($elementValue)
    {
        // Comment output is rendered raw so this form's help text can carry markup, so an
        // unusable stored value has to be escaped before it is named.
        $days = $this->escaper->escapeHtml(StoredTerm::days($elementValue) ?? trim((string)$elementValue));

        // No term semantics to qualify, so the terms type does not enter into it.
        if (StoredTerm::isUnusable($elementValue)) {
            return (string)__(
                'Legacy setting currently holds "%1", which is not a usable number of days.'
                . ' It is no longer supported and cannot be edited, and this section cannot be saved'
                . ' until it is removed. Choose Remove to clear it.',
                $days
            );
        }

        if ($this->endOfMonth->isConfigured($this->storedType())) {
            return (string)__(
                'Legacy setting. This offers a custom term of %1 days after the end of the month.'
                . ' It is no longer supported and cannot be edited. Choose Remove to withdraw it,'
                . ' or use the payment terms above to change what you offer.',
                $days
            );
        }

        return (string)__(
            'Legacy setting. This offers a custom term of %1 days from fulfilment.'
            . ' It is no longer supported and cannot be edited. Choose Remove to withdraw it,'
            . ' or use the payment terms above to change what you offer.',
            $days
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
