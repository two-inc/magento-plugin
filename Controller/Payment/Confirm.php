<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Controller\Payment;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
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
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
    * @var AddressRepositoryInterface
     */
    private $addressRepository;

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
        OrderService $orderService,
        OrderSender $orderSender,
        ConfigRepository $configRepository
    ) {
        $this->addressRepository = $addressRepository;
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
        $order = null;
        try {
            $order = $this->orderService->getOrderByReference();
            $twoOrder = $this->orderService->getTwoOrderFromApi($order);
            if (isset($twoOrder['state']) && $twoOrder['state'] != 'UNVERIFIED') {
                if (in_array($twoOrder['state'], ['VERIFIED', 'CONFIRMED'])) {
                    $this->orderService->confirmOrder($order);
                }
                $this->orderSender->send($order);
                try {
                    $this->updateCustomerAddress($order, $twoOrder);
                } catch (LocalizedException $exception) {
                    $message = __(
                        "Failed to update %1 customer address: %2",
                        $this->configRepository::PROVIDER,
                        $exception->getMessage()
                    );
                    $this->orderService->addOrderComment($order, $message);
                } catch (Exception $exception) {
                    $message = __(
                        "Failed to update %1 customer address: %2",
                        $this->configRepository::PROVIDER,
                        $exception->getMessage()
                    );
                    $this->orderService->addOrderComment($order, $message);
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
            if ($order !== null) {
                $this->orderService->failOrder($order, $exception->getMessage());
            }
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
        }
    }

    /**
     * Update customer address
     *
     * @param $order
     * @param array $twoOrder
     *
     * @return void
     * @throws Exception
     */
    private function updateCustomerAddress($order, $twoOrder)
    {
        $customerAddress = null;
        if ($order->getBillingAddress()->getCustomerAddressId()) {
            $customerAddress = $this->addressRepository->getById(
                $order->getBillingAddress()->getCustomerAddressId()
            );
            if ($customerAddress && $customerAddress->getId()) {
                if (isset($twoOrder['buyer']['company']['organization_number'])) {
                    $customerAddress->setData('company_id', $twoOrder['buyer']['company']['organization_number']);
                }
                if (isset($twoOrder['buyer']['company']['company_name'])) {
                    $customerAddress->setData('company_name', $twoOrder['buyer']['company']['company_name']);
                }
                if (isset($twoOrder['buyer_department'])) {
                    $customerAddress->setData('department', $twoOrder['buyer_department']);
                }
                if (isset($twoOrder['buyer_project'])) {
                    $customerAddress->setData('project', $twoOrder['buyer_project']);
                }
                $this->addressRepository->save($customerAddress);
            }
        }
    }
}
