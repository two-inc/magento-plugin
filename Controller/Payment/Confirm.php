<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Payment\OrderService;

/**
 * Payment confirm controller
 */
class Confirm extends Action
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
    * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var SearchCriteriaInterface
     */
    private $searchCriteria;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var OrderSender
     */
    private $orderSender;

    public function __construct(
        Context $context,
        AddressRepositoryInterface $addressRepository,
        SearchCriteriaInterface $searchCriteria,
        OrderService $orderService,
        OrderSender $orderSender,
        ConfigRepository $configRepository
    ) {
        $this->addressRepository = $addressRepository;
        $this->searchCriteria = $searchCriteria;
        $this->orderService = $orderService;
        $this->orderSender = $orderSender;
        $this->configRepository = $configRepository;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     */
    public function execute()
    {
        try {
            $order = $this->orderService->getOrderByReference();
            $twoOrder = $this->orderService->getTwoOrderFromApi($order);
            if (isset($twoOrder['state']) && $twoOrder['state'] != 'UNVERIFIED') {
                if (in_array($twoOrder['state'], ['VERIFIED', 'CONFIRMED'])) {
                    $this->orderService->confirmOrder($order);
                }
                $this->orderSender->send($order);
                try {
                    $this->saveAddress($order, $twoOrder);
                } catch (Exception $exception) {
                    $this->orderService->addOrderComment($order, $exception->getMessage());
                }
                $this->orderService->processOrder($order, $twoOrder['id']);
                return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/onepage/success'));
            } else {
                $message = __(
                    'Unable to retrieve payment information for your invoice purchase with %1. ' .
                    'The cart will be restored.',
                    $this->configRepository::PROVIDER
                );
                if (!empty($twoOrder['decline_reason'])) {
                    $message = __('%1 Decline reason: %2', $message, $twoOrder['decline_reason']);
                }
                $this->orderService->addOrderComment($order, $message);
                throw new LocalizedException($message);
            }
        } catch (Exception $exception) {
            $this->orderService->restoreQuote();
            if (isset($order)) {
                $this->orderService->failOrder($order, $exception->getMessage());
            }

            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
        }
    }

    /**
     * Save address
     *
     * @param $order
     * @param array $twoOrder
     *
     * @throws Exception
     */
    private function saveAddress($order, $twoOrder)
    {
        if ($order->getCustomerId()) {
            if ($order->getBillingAddress()->getCustomerAddressId()) {
                $customerAddress = $this->addressRepository->getById(
                    $order->getBillingAddress()->getCustomerAddressId()
                );
            } else {
                $this->searchCriteria
                    ->setField('parent_id')
                    ->setValue($order->getCustomerId())
                    ->setConditionType('eq');
                $customerAddressCollection = $this->addressRepository
                    ->getList($this->searchCriteria)
                    ->getItems();
                $customerAddress = $customerAddressCollection[0] ?? null;
            }
            if ($customerAddress && $customerAddress->getId()) {
                $this->copyMetadataToAddress(
                    $twoOrder,
                    $customerAddress
                );
                $this->addressRepository->save($customerAddress);
            }
        } else {
            // Save metadata to shipping address
            $shippingAddress = $order->getShippingAddress();
            $this->copyMetadataToAddress(
                $twoOrder,
                $shippingAddress
            );
            $shippingAddress->save();
            // Save metadata to billing address
            $billingAddress = $order->getBillingAddress();
            $this->copyMetadataToAddress(
                $twoOrder,
                $billingAddress
            );
            $billingAddress->save();
        }
    }

    /**
     * Set metadata to customer address
     *
     * @param array $twoOrder
     * @param $address
     *
     * @return void
     * @throws Exception
     */
    public function copyMetadataToAddress(array $twoOrder, $address)
    {
        if (isset($twoOrder['buyer']['company']['organization_number'])) {
            $address->setData('company_id', $twoOrder['buyer']['company']['organization_number']);
        }
        if (isset($twoOrder['buyer']['company']['company_name'])) {
            $address->setData('company_name', $twoOrder['buyer']['company']['company_name']);
        }
        if (isset($twoOrder['buyer_department'])) {
            $address->setData('department', $twoOrder['buyer_department']);
        }
        if (isset($twoOrder['buyer_project'])) {
            $address->setData('project', $twoOrder['buyer_project']);
        }
    }
}
