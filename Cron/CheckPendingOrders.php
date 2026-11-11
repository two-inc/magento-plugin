<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Throwable;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolver;

/**
 * Checks with Two the orders whose buyer verified (or may have) but never
 * came back to the shop, and settles them as the buyer's return would have
 * (TWO-26300). Runs every 15 minutes in its own cron group, so a slow Two API
 * never holds up the default group.
 *
 * Picks orders still waiting for the buyer that are older than the threshold
 * (30 minutes by default) and younger than 7 days, and were not checked in the
 * last 15 minutes: oldest check first, 50 per run. Seven days covers Two's own
 * cancellation of unverified orders (2 days by default, set per merchant);
 * past that the admin's Check status button still works.
 */
class CheckPendingOrders
{
    public const BATCH_SIZE = 50;

    /** Hidden setting: minutes an order waits before the first check. */
    public const XML_PATH_CHECK_AFTER_MINUTES = 'payment/two_payment/pending_check_after_minutes';

    public const DEFAULT_CHECK_AFTER_MINUTES = 30;
    public const MAX_AGE_DAYS = 7;
    public const RECHECK_AFTER_MINUTES = 15;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var PendingOrderResolver */
    private $resolver;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        CollectionFactory $collectionFactory,
        PendingOrderResolver $resolver,
        ScopeConfigInterface $scopeConfig,
        BrandOverlayRegistryInterface $overlayRegistry,
        LogRepository $logRepository
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->resolver = $resolver;
        $this->scopeConfig = $scopeConfig;
        $this->overlayRegistry = $overlayRegistry;
        $this->logRepository = $logRepository;
    }

    public function execute(): void
    {
        $tally = [];
        foreach ($this->selectOrders(time()) as $order) {
            if (!$this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())) {
                continue;
            }
            try {
                $outcome = $this->resolver->resolve($order, PendingOrderResolver::TRIGGER_SCHEDULED)->getOutcome();
            } catch (Throwable $exception) {
                // The resolver records its own failures; this catches a
                // failure to take or release the lock, so one order cannot
                // end the run.
                $this->logRepository->addErrorLog('pending-order-check-failed', [
                    'order_increment_id' => $order->getIncrementId(),
                    'trigger' => PendingOrderResolver::TRIGGER_SCHEDULED,
                    'message' => $exception->getMessage(),
                ]);
                $outcome = PendingOrderResolver::OUTCOME_FAILED;
            }
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }
        $this->logRepository->addDebugLog('pending-order-check-run', ['checked' => array_sum($tally)] + $tally);
    }

    /**
     * @param int $now Unix time
     * @return Order[]
     */
    public function selectOrders(int $now): array
    {
        $after = (int)$this->scopeConfig->getValue(self::XML_PATH_CHECK_AFTER_MINUTES);
        if ($after <= 0) {
            $after = self::DEFAULT_CHECK_AFTER_MINUTES;
        }
        // sales_order timestamps are stored in UTC.
        $utc = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', $now - $seconds);

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('status', Two::STATUS_PENDING)
            ->addFieldToFilter('two_order_id', ['notnull' => true])
            ->addFieldToFilter('created_at', ['lteq' => $utc($after * 60)])
            ->addFieldToFilter('created_at', ['gteq' => $utc(self::MAX_AGE_DAYS * 86400)])
            // A list of conditions on one field is an OR.
            ->addFieldToFilter(OrderService::PENDING_CHECKED_AT, [
                ['null' => true],
                ['lt' => $utc(self::RECHECK_AFTER_MINUTES * 60)],
            ])
            // Never-checked orders (NULL) sort first.
            ->setOrder(OrderService::PENDING_CHECKED_AT, 'ASC')
            ->setPageSize(self::BATCH_SIZE)
            ->setCurPage(1);

        return array_values($collection->getItems());
    }
}
