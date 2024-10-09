<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Controller\Payment;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Service\Payment\OrderService;

/**
 * Payment confirm controller
 */
class Confirm extends Action
{
    public const STATE_CONFIRMED = 'CONFIRMED';
    public const STATE_VERIFIED = 'VERIFIED';

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
            if (isset($twoOrder['state']) &&
                (($twoOrder['state'] == self::STATE_VERIFIED) || ($twoOrder['state'] == self::STATE_CONFIRMED))
            ) {
                $this->orderService->confirmOrder($order);
                $this->orderSender->send($order);
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
                        $this->saveAddressMetadata(
                            $twoOrder,
                            $customerAddress
                        );
                    }
                } else {
                    $this->saveAddressMetadata(
                        $twoOrder,
                        $order->getShippingAddress()
                    );
                    $this->saveAddressMetadata(
                        $twoOrder,
                        $order->getBillingAddress()
                    );
                }
                $this->orderService->processOrder($order, $twoOrder['id']);
                return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/onepage/success'));
            } else {
                $message = __(
                    'Unable to retrieve payment information for your invoice purchase with %1. ' .
                    'The cart will be restored.',
                    $this->configRepository::PRODUCT_NAME
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
     * Set metadata to customer address
     *
     * @param array $twoOrder
     * @param $address
     *
     * @throws Exception
     */
    public function saveAddressMetadata(array $twoOrder, $address)
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
        $address->save();
    }
}
