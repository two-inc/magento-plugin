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
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
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
 * held and again just before acting, so whichever arrives second finds the
 * work done, and a cancel that lands while Two is being asked is not undone.
 *
 * A check that changes nothing writes only the time of the check and its
 * comment, never the whole order. On the buyer's return, a failed return
 * (restored cart, failed order) is also wound up while the lock is held.
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
    /** Reading or acting on the order failed. */
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

    /** @var OrderResource */
    private $orderResource;

    /** @var HistoryFactory */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $historyRepository;

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
        OrderResource $orderResource,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository,
        BrandRegistryInterface $brandRegistry,
        LogRepository $logRepository,
        AddressRepositoryInterface $addressRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderService = $orderService;
        $this->orderSender = $orderSender;
        $this->lockManager = $lockManager;
        $this->orderResource = $orderResource;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
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
            $this->log($resolution->getOrder(), $trigger, $resolution->getTwoState(), $resolution->getOutcome());
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

            // A cancel without the lock (the buyer's cancel return, the admin's
            // Cancel) may have landed while Two was being asked: act on the
            // order as it stands now, never on the copy read before.
            $checkedAt = $order->getData(OrderService::PENDING_CHECKED_AT);
            $order = $this->orderService->reloadOrder($order);
            $order->setData(OrderService::PENDING_CHECKED_AT, $checkedAt);
            if (!$this->orderService->isAwaitingVerification($order)) {
                return new PendingOrderResolution(self::OUTCOME_NOT_WAITING, $order, $state);
            }

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
            if ($state === 'UNVERIFIED') {
                return $this->unverified($order, $trigger);
            }
            return $this->unchanged($order, $trigger, $state);
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
                // As before: a return that cannot be settled fails, with the
                // full account for the merchant and a safe message for the buyer.
                return $this->failReturn($order, $state, $exception);
            }
            $message = (string)__(
                'Could not check the order with %1: %2',
                $this->product(),
                $exception->getMessage()
            );
            $this->recordCheck($order, $trigger === self::TRIGGER_ADMIN ? $message : null, $trigger);
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
     * a cancel back; on the buyer's return, fail it as any failed return is.
     */
    private function cancel(
        Order $order,
        string $trigger,
        string $outcome,
        string $state,
        string $comment
    ): PendingOrderResolution {
        if ($trigger !== self::TRIGGER_BUYER_RETURN) {
            $this->orderService->cancelLocally($order, $comment);
            return new PendingOrderResolution($outcome, $order, $state, $comment);
        }
        $this->orderService->addOrderComment($order, $comment);
        $buyerMessage = $this->orderService->failBuyerReturn($order, new LocalizedException(__(
            'Your invoice purchase with %1 has been cancelled. The cart will be restored.',
            $this->product()
        )));
        return new PendingOrderResolution($outcome, $order, $state, $comment, $buyerMessage);
    }

    /**
     * The buyer has not finished verification. The scheduled check and the
     * button change nothing; a buyer back at the shop without verifying has
     * the return failed, as it always was.
     */
    private function unverified(Order $order, string $trigger): PendingOrderResolution
    {
        $state = 'UNVERIFIED';
        if ($trigger === self::TRIGGER_BUYER_RETURN) {
            $comment = (string)__('Unable to confirm %1 order with %2 state.', $this->product(), $state);
            $this->orderService->addOrderComment($order, $comment);
            $buyerMessage = $this->orderService->failBuyerReturn($order, new LocalizedException(__(
                'Your invoice purchase with %1 could not be processed. The cart will be restored.',
                $this->product()
            )));
            return new PendingOrderResolution(self::OUTCOME_UNVERIFIED, $order, $state, $comment, $buyerMessage);
        }
        $message = (string)__('Checked with %1: the buyer has not finished verification.', $this->product());
        $this->recordCheck($order, $trigger === self::TRIGGER_ADMIN ? $message : null, $trigger);
        return new PendingOrderResolution(self::OUTCOME_UNVERIFIED, $order, $state, $message);
    }

    /**
     * Two reports a state this module does not act on (REFUNDED, anything
     * new, or none): change nothing, whichever trigger asked, and tell the
     * merchant once per state. The order stays waiting, so a later check or
     * the button can still settle it.
     */
    private function unchanged(Order $order, string $trigger, string $state): PendingOrderResolution
    {
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
        $this->recordCheck($order, $this->hasComment($order, $message) ? null : $message, $trigger);
        return new PendingOrderResolution(self::OUTCOME_UNCHANGED, $order, $state, $message);
    }

    private function failReturn(Order $order, string $state, Throwable $exception): PendingOrderResolution
    {
        $buyerMessage = $this->orderService->failBuyerReturn($order, $exception);
        return new PendingOrderResolution(
            self::OUTCOME_FAILED,
            $order,
            $state,
            $exception->getMessage(),
            $buyerMessage
        );
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
     * Write the time of the check, and a comment if any, and nothing else: a
     * whole-order save here could put back a state another request has just
     * changed. A failed write never ends a scheduled run.
     */
    private function recordCheck(Order $order, ?string $comment, string $trigger): void
    {
        try {
            $this->orderResource->getConnection()->update(
                $this->orderResource->getMainTable(),
                [OrderService::PENDING_CHECKED_AT => $order->getData(OrderService::PENDING_CHECKED_AT)],
                ['entity_id = ?' => (int)$order->getId()]
            );
            if ($comment !== null) {
                $history = $this->historyFactory->create();
                $history->setParentId($order->getId())
                    ->setComment($comment)
                    ->setEntityName('order')
                    ->setStatus($order->getStatus())
                    ->setIsCustomerNotified(false);
                $this->historyRepository->save($history);
            }
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
        foreach ($order->getStatusHistoryCollection() ?: [] as $history) {
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
