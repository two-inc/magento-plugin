<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Tax\Model\Calculation;

/**
 * The shop's own tax rates for an order's lines, read through core's
 * calculation (TWO-26153).
 *
 * Core drops 0% rates from the taxes it applies, so an order keeps no record
 * of the rate that zeroed a line. Calculation::getAppliedRates() still
 * returns them, each with its rate code, so the rates are looked up again
 * here with the order's addresses, the order-time customer group's tax class
 * and the line's product tax class, as core's quote-time calculator does.
 */
class ShopTaxRates
{
    /**
     * @var Calculation
     */
    private $calculation;

    /**
     * @var GroupRepositoryInterface
     */
    private $groupRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var Order|null the order $request was built for
     */
    private $order;

    /**
     * @var DataObject|null
     */
    private $request;

    public function __construct(
        Calculation $calculation,
        GroupRepositoryInterface $groupRepository,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->calculation = $calculation;
        $this->groupRepository = $groupRepository;
        $this->customerRepository = $customerRepository;
    }

    /**
     * The address core taxes the order's lines on (tax/calculation/based_on):
     * normally delivery, billing for a virtual order.
     *
     * @return array{country: string, postcode: string}
     */
    public function taxAddress(Order $order): array
    {
        $request = $this->request($order);

        return [
            'country' => strtoupper(trim((string)$request->getCountryId())),
            'postcode' => (string)$request->getPostcode(),
        ];
    }

    /**
     * Every rate the shop's tax rules apply to the product tax class at the
     * order's tax address, 0% rates included, in core's order. Class 0 (None)
     * has none.
     *
     * @return array<int, array{code: string, percent: float}>
     */
    public function rates(Order $order, int $productClassId): array
    {
        $request = clone $this->request($order);
        $request->setProductClassId($productClassId);
        $rates = [];
        foreach ($this->calculation->getAppliedRates($request) as $applied) {
            foreach ($applied['rates'] ?? [] as $rate) {
                $rates[] = ['code' => (string)$rate['code'], 'percent' => (float)$rate['percent']];
            }
        }

        return $rates;
    }

    /**
     * The rate request core's calculator builds for the order: shipping and
     * billing separately so tax/calculation/based_on picks, the store, the
     * current tax class of the order-time customer group, and the customer
     * id for core's default-address fallback. Built once per order.
     */
    private function request(Order $order): DataObject
    {
        if ($this->order === $order && $this->request !== null) {
            return $this->request;
        }
        $billing = $order->getBillingAddress();
        $customerClassId = $this->customerTaxClassId($order);
        $customerId = $order->getCustomerId();
        // A null class with a customer id makes core load that customer, which throws once they are deleted.
        if ($customerClassId === null && $customerId && !$this->customerExists((int)$customerId)) {
            $customerId = null;
        }
        $this->order = $order;
        $this->request = $this->calculation->getRateRequest(
            $order->getShippingAddress() ?: $billing,
            $billing,
            $customerClassId,
            (int)$order->getStoreId(),
            $customerId
        );

        return $this->request;
    }

    /**
     * Current tax class of the customer group the order was placed under, or
     * null when it has none or the group is gone, as core's
     * Quote::getCustomerTaxClassId() resolves it.
     */
    private function customerTaxClassId(Order $order): ?int
    {
        $groupId = $order->getCustomerGroupId();
        if ($groupId === null) {
            return null;
        }
        try {
            return (int)$this->groupRepository->getById((int)$groupId)->getTaxClassId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function customerExists(int $customerId): bool
    {
        try {
            $this->customerRepository->getById($customerId);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }
}
