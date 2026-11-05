<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Payment;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Throwable;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Settles an order still waiting for the buyer's verification by asking Two
 * what became of it, then acting on the answer (TWO-26300).
 *
 * Three triggers share it: the buyer's return to the shop, the scheduled check
 * of orders whose buyer never came back, and the admin's Check status button.
 * They share one lock per order, and the order is read afresh once the lock is
 * held, so whichever arrives second finds the work done.
 *
 * For the buyer's return the resolver never fails the order itself: it reports
 * the outcome and the confirm controller restores the cart and fails the order
 * as for any other failed return. An error reading or confirming the order is
 * rethrown on that path for the same reason, and caught and recorded on the
 * other two, so the next scheduled run retries.
 */
class PendingOrderResolver
{
    public const TRIGGER_BUYER_RETURN = 'buyer_return';
    public const TRIGGER_SCHEDULED = 'scheduled_check';
    public const TRIGGER_ADMIN = 'admin_check';

    /** VERIFIED at Two: confirmed with Two and processed here. */
    public const OUTCOME_CONFIRMED = 'confirmed';
    /** Two had already confirmed it: processed here without a confirm. */
    public const OUTCOME_PROCESSED = 'processed';
    /** Cancelled at Two, so cancelled here (or, on the buyer's return, failed). */
    public const OUTCOME_CANCELLED = 'cancelled';
    /** Declined by Two, so cancelled here (or, on the buyer's return, failed). */
    public const OUTCOME_DECLINED = 'declined';
    /** The buyer has not finished verification. */
    public const OUTCOME_UNVERIFIED = 'unverified';
    /** Any other state: nothing changed, the merchant is told to look. */
    public const OUTCOME_UNCHANGED = 'unchanged';
    /** The order was processing by the time the lock was held. */
    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';
    /** The order no longer waits for the buyer. */
    public const OUTCOME_NOT_WAITING = 'not_waiting';
    /** Another request holds the order's lock. */
    public const OUTCOME_BUSY = 'busy';
    /** Reading or acting on the order failed; nothing changed. */
    public const OUTCOME_FAILED = 'failed';

    /** Shared by every trigger, so they never act on one order at once. */
    public const LOCK_PREFIX = 'two_gateway_pending_order_';

    /** How long the buyer's return and the button wait for the lock. */
    public const LOCK_WAIT_SECONDS = 10;

    private const PROCESSED_STATES = [
        'CONFIRMED',
        'FULFILLING',
        'FULFILLED',
        'DELIVERED',
        'FULFILMENT_NEEDS_MANUAL_RESOLUTION',
    ];

    /** Values of Two's separate `status` field that Two never approves. */
    private const DECLINED_STATUSES = ['REJECTED', 'DECLINED'];

    /** @var OrderService */
    private $orderService;

    /** @var OrderSender */
    private $orderSender;

    /** @var LockManagerInterface */
    private $lockManager;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var LogRepository */
    private $logRepository;

    /** @var AddressRepositoryInterface */
    private $addressRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    public function __construct(
        OrderService $orderService,
        OrderSender $orderSender,
        LockManagerInterface $lockManager,
        OrderRepositoryInterface $orderRepository,
        BrandRegistryInterface $brandRegistry,
        LogRepository $logRepository,
        AddressRepositoryInterface $addressRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderService = $orderService;
        $this->orderSender = $orderSender;
        $this->lockManager = $lockManager;
        $this->orderRepository = $orderRepository;
        $this->brandRegistry = $brandRegistry;
        $this->logRepository = $logRepository;
        $this->addressRepository = $addressRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @param Order $order
     * @param string $trigger one of the TRIGGER_* constants
     * @param string $actor the admin user, for the button's order comment
     * @return PendingOrderResolution
     * @throws Throwable on the buyer's return, when reading or confirming fails
     */
    public function resolve(Order $order, string $trigger, string $actor = ''): PendingOrderResolution
    {
        $lockName = self::LOCK_PREFIX . $order->getId();
        // The scheduled check never waits: an order someone else is settling
        // is simply left for the next run.
        $wait = $trigger === self::TRIGGER_SCHEDULED ? 0 : self::LOCK_WAIT_SECONDS;
        if (!$this->lockManager->lock($lockName, $wait)) {
            $this->log($order, $trigger, '', self::OUTCOME_BUSY);
            return new PendingOrderResolution(self::OUTCOME_BUSY, $order);
        }
        try {
            $order = $this->orderService->reloadOrder($order);
            $resolution = $this->resolveLocked($order, $trigger, $actor);
            $this->log($order, $trigger, $resolution->getTwoState(), $resolution->getOutcome());
            return $resolution;
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function resolveLocked(Order $order, string $trigger, string $actor): PendingOrderResolution
    {
        if ($this->orderService->isConfirmed($order)) {
            return new PendingOrderResolution(self::OUTCOME_ALREADY_PROCESSED, $order);
        }
        if (!$this->orderService->isAwaitingVerification($order)) {
            return new PendingOrderResolution(self::OUTCOME_NOT_WAITING, $order);
        }
        $order->setData(OrderService::PENDING_CHECKED_AT, gmdate('Y-m-d H:i:s'));

        $state = '';
        try {
            $twoOrder = $this->orderService->getTwoOrderFromApi($order);
            $state = (string)($twoOrder['state'] ?? '');
            $status = (string)($twoOrder['status'] ?? '');

            if (in_array($status, self::DECLINED_STATUSES, true)) {
                $comment = (string)__('%1 declined this order.', $this->product());
                return $this->cancel($order, $trigger, self::OUTCOME_DECLINED, $state, $comment);
            }
            if ($state === 'CANCELLED') {
                $comment = (string)__(
                    '%1 reports this order as cancelled. Cancelled here to match.',
                    $this->product()
                );
                return $this->cancel($order, $trigger, self::OUTCOME_CANCELLED, $state, $comment);
            }
            if ($state === 'VERIFIED') {
                $this->orderService->confirmOrder($order);
                return $this->process($order, $twoOrder, $trigger, $actor, self::OUTCOME_CONFIRMED);
            }
            if (in_array($state, self::PROCESSED_STATES, true)) {
                return $this->process($order, $twoOrder, $trigger, $actor, self::OUTCOME_PROCESSED);
            }
            return $this->leave($order, $trigger, $state);
        } catch (Throwable $exception) {
            $this->logRepository->addErrorLog('pending-order-check-failed', [
                'order_increment_id' => $order->getIncrementId(),
                'two_order_id' => $order->getTwoOrderId(),
                'trigger' => $trigger,
                'two_state' => $state,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
            if ($trigger === self::TRIGGER_BUYER_RETURN) {
                throw $exception;
            }
            $message = (string)__(
                'Could not check the order with %1: %2',
                $this->product(),
                $exception->getMessage()
            );
            $this->saveQuietly($order, $trigger === self::TRIGGER_ADMIN ? $message : null, $trigger);
            return new PendingOrderResolution(self::OUTCOME_FAILED, $order, $state, $message);
        }
    }

    /**
     * Two holds the order as placed: process it here exactly as the buyer's
     * return does, buyer confirmation email included.
     */
    private function process(
        Order $order,
        array $twoOrder,
        string $trigger,
        string $actor,
        string $outcome
    ): PendingOrderResolution {
        $message = '';
        if ($trigger !== self::TRIGGER_BUYER_RETURN) {
            $message = $this->recoveryComment($trigger, $actor, $outcome === self::OUTCOME_PROCESSED);
            $this->orderService->addOrderComment($order, $message);
        }
        $this->orderSender->send($order);
        try {
            $this->updateCustomerAddress($order, $twoOrder);
        } catch (Exception $exception) {
            $this->orderService->addOrderComment($order, __(
                "Failed to update %1 customer address: %2",
                $this->brandRegistry->getProductName(),
                $exception->getMessage()
            ));
        }
        $this->orderService->processOrder($order, (string)$twoOrder['id']);
        return new PendingOrderResolution($outcome, $order, (string)($twoOrder['state'] ?? ''), $message);
    }

    /**
     * Two has cancelled or declined the order. Cancel it here without sending
     * a cancel back. On the buyer's return the controller fails it instead,
     * as for any other failed return.
     */
    private function cancel(
        Order $order,
        string $trigger,
        string $outcome,
        string $state,
        string $comment
    ): PendingOrderResolution {
        if ($trigger === self::TRIGGER_BUYER_RETURN) {
            $this->orderService->addOrderComment($order, $comment);
        } else {
            $this->orderService->cancelLocally($order, $comment);
        }
        return new PendingOrderResolution($outcome, $order, $state, $comment);
    }

    /**
     * Two reports a state that leaves the order waiting (UNVERIFIED) or one
     * this module does not act on (REFUNDED, anything new): change nothing.
     */
    private function leave(Order $order, string $trigger, string $state): PendingOrderResolution
    {
        $outcome = $state === 'UNVERIFIED' ? self::OUTCOME_UNVERIFIED : self::OUTCOME_UNCHANGED;
        if ($trigger === self::TRIGGER_BUYER_RETURN) {
            $comment = (string)__(
                'Unable to confirm %1 order with %2 state.',
                $this->product(),
                $state !== '' ? $state : 'undefined'
            );
            $this->orderService->addOrderComment($order, $comment);
            return new PendingOrderResolution($outcome, $order, $state, $comment);
        }
        $comment = null;
        if ($outcome === self::OUTCOME_UNVERIFIED) {
            $message = (string)__('Checked with %1: the buyer has not finished verification.', $this->product());
            $comment = $trigger === self::TRIGGER_ADMIN ? $message : null;
        } else {
            $this->logRepository->addErrorLog('pending-order-unknown-state', [
                'order_increment_id' => $order->getIncrementId(),
                'two_order_id' => $order->getTwoOrderId(),
                'trigger' => $trigger,
                'two_state' => $state,
            ]);
            $message = (string)__(
                '%1 reports state %2; not changed. Check the order in the %1 portal.',
                $this->product(),
                $state !== '' ? $state : 'undefined'
            );
            // Once per state, so a run every 15 minutes does not fill the history.
            $comment = $this->hasComment($order, $message) ? null : $message;
        }
        $this->saveQuietly($order, $comment, $trigger);
        return new PendingOrderResolution($outcome, $order, $state, $message);
    }

    private function recoveryComment(string $trigger, string $actor, bool $alreadyConfirmed): string
    {
        $byAdmin = $trigger === self::TRIGGER_ADMIN;
        $actor = $actor !== '' ? $actor : (string)__('an admin user');
        // phpcs:disable Generic.Files.LineLength.TooLong -- one literal each, so Magento's collector harvests it
        if ($alreadyConfirmed) {
            return $byAdmin
                ? (string)__('The buyer verified this order with %1 but did not return to the shop. Processed by %2: %1 had already confirmed it.', $this->product(), $actor)
                : (string)__('The buyer verified this order with %1 but did not return to the shop. Processed by the scheduled check: %1 had already confirmed it.', $this->product());
        }
        return $byAdmin
            ? (string)__('The buyer verified this order with %1 but did not return to the shop. Confirmed with %1 by %2.', $this->product(), $actor)
            : (string)__('The buyer verified this order with %1 but did not return to the shop. Confirmed with %1 by the scheduled check.', $this->product());
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * Record the check (and a comment, if any) without letting a failed save
     * end a scheduled run.
     */
    private function saveQuietly(Order $order, ?string $comment, string $trigger): void
    {
        try {
            if ($comment !== null) {
                $this->orderService->addOrderComment($order, $comment);
            }
            $this->orderRepository->save($order);
        } catch (Throwable $exception) {
            $this->logRepository->addErrorLog('pending-order-check-save-failed', [
                'order_increment_id' => $order->getIncrementId(),
                'trigger' => $trigger,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function hasComment(Order $order, string $comment): bool
    {
        foreach ($order->getStatusHistories() ?: [] as $history) {
            if ((string)$history->getComment() === $comment) {
                return true;
            }
        }
        return false;
    }

    /**
     * The product name, %1 in every phrase here.
     */
    private function product(): string
    {
        return (string)$this->brandRegistry->getProductName();
    }

    private function log(Order $order, string $trigger, string $state, string $outcome): void
    {
        $this->logRepository->addDebugLog('pending-order-check', [
            'order_increment_id' => $order->getIncrementId(),
            'two_order_id' => $order->getTwoOrderId(),
            'trigger' => $trigger,
            'two_state' => $state,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Copy the company details Two holds onto the buyer's saved address. Moved
     * here unchanged from the confirm controller.
     *
     * @param Order $order
     * @param array $twoOrder
     * @return void
     * @throws Exception
     */
    private function updateCustomerAddress(Order $order, array $twoOrder): void
    {
        $customerAddress = null;
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress->getCustomerAddressId()) {
            $customerAddress = $this->addressRepository->getById(
                $billingAddress->getCustomerAddressId()
            );
        }
        if ($customerAddress == null && $order->getCustomerId()) {
            // Find the customer address that matches the billing address.
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
            $this->orderService->addOrderComment($order, __(
                "%1 customer address updated.",
                $this->brandRegistry->getProductName(),
            ));
        }
    }
}
