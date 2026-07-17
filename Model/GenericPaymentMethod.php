<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\UrlCookie;

/**
 * Brand-parameterised variant of the Two payment method.
 *
 * Brand-overlay packages declare a virtualType of this class with
 * `code` (Magento payment method code) and `brand` (BrandRegistryInterface)
 * constructor arguments. Magento's ObjectManager resolves the named
 * arguments at instantiation time, so a single virtualType per brand
 * is sufficient to expose a distinct payment method without copying
 * the 600-line Two class.
 *
 * Brand\BrandPaymentMethodFactory instantiates this class with the
 * active brand's code and the DI-resolved BrandRegistryInterface
 * (DescriptorBackedBrandRegistry), so legacy overlay virtualTypes
 * keep working alongside the brand.xml-sourced descriptor pipeline.
 *
 * Example brand-overlay binding (legacy, still supported):
 *
 *   <virtualType name="ABN\Gateway\Model\AbnPayment"
 *                type="Two\Gateway\Model\GenericPaymentMethod">
 *       <arguments>
 *           <argument name="code" xsi:type="string">acme_payment</argument>
 *           <argument name="brand" xsi:type="object">ABN\Gateway\Model\AbnBrand</argument>
 *       </arguments>
 *   </virtualType>
 */
class GenericPaymentMethod extends Two
{
    /**
     * @param string $code Magento payment-method code (overlay-specific).
     * @param BrandRegistryInterface $brand Overlay brand binding.
     */
    public function __construct(
        string $code,
        BrandRegistryInterface $brand,
        RequestInterface $request,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        ConfigRepository $configRepository,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlCookie $urlCookie,
        ComposeOrder $composeOrder,
        ComposeRefund $composeRefund,
        ComposeCapture $composeCapture,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        OrderRepositoryInterface $orderRepository,
        Adapter $apiAdapter,
        LogRepository $logRepository,
        MinimumOrderGate $minimumOrderGate,
        MinimumOrderProvider $minimumOrderProvider,
        MerchantMinimumResolver $merchantMinimumResolver,
        ConfigDataCollectionFactory $configDataCollectionFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $request,
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $configRepository,
            $brand,
            $paymentData,
            $scopeConfig,
            $logger,
            $urlCookie,
            $composeOrder,
            $composeRefund,
            $composeCapture,
            $historyFactory,
            $orderStatusHistoryRepository,
            $orderRepository,
            $apiAdapter,
            $logRepository,
            $minimumOrderGate,
            $minimumOrderProvider,
            $merchantMinimumResolver,
            $configDataCollectionFactory,
            $resource,
            $resourceCollection,
            $data
        );
        // Brand-overlay payment-method code, e.g. 'acme_payment'. Replaces
        // the hardcoded `self::CODE` baked into the parent Two class so a
        // single class can back multiple brand-overlay virtualTypes.
        $this->_code = $code;
    }
}
