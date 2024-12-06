<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
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
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

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
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderService $orderService,
        OrderSender $orderSender,
        ConfigRepository $configRepository
    ) {
        $this->addressRepository = $addressRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
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
                    $this->orderSender->send($order);
                }
                try {
                    $this->updateCustomerAddress($order, $twoOrder);
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
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress->getCustomerAddressId()) {
            // Try to load the customer address by ID
            $customerAddress = $this->addressRepository->getById(
                $billingAddress->getCustomerAddressId()
            );
        }
        if ($customerAddress == null && $order->getCustomerId()) {
            // Build a search criteria to find customer addresse that matches the billing address
            $keys = [
                'parent_id',
                'firstname',
                'middlename',
                'lastname',
                'street',
                'city',
                'postcode',
                'country_id',
                'region_id',
                'region',
                'telephone',
                'company',
            ];
            foreach ($keys as $key) {
                $value = ($key === 'parent_id')
                    ? $order->getCustomerId()
                    : $billingAddress->getData($key);
                if (!empty($value)) {
                    $this->searchCriteriaBuilder->addFilter($key, $value, 'eq');
                }
            }
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $customerAddressCollection = $this->addressRepository
                ->getList($searchCriteria)
                ->getItems();
            $customerAddress = $customerAddressCollection[0] ?? null;
        }
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
            $message = __(
                "%1 customer address updated.",
                $this->configRepository::PROVIDER,
            );
            $this->orderService->addOrderComment($order, $message);
        }
    }
}
