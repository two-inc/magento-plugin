<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Payment\OrderService;

/**
 * After Order Cancel Observer
 *
 * Mirrors a Magento-side cancellation back to the Two API. Without this,
 * an order cancelled in the Magento admin stays at Approved + Confirmed
 * in the Two backend — creating a desync that has to be cleared by hand.
 *
 * On API failure we throw — Magento's admin cancel controller catches the
 * exception, surfaces the message to the merchant, and the Magento-side
 * cancellation is not persisted. Keeps the two systems in lockstep at the
 * cost of blocking cancels during a Two outage; the trade is intentional.
 */
class SalesOrderCancelAfter implements ObserverInterface
{
    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /**
     * @param OrderService $orderService
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderService $orderService,
        LoggerInterface $logger,
        BrandRegistryInterface $brandRegistry,
        BrandOverlayRegistryInterface $overlayRegistry
    ) {
        $this->orderService = $orderService;
        $this->logger = $logger;
        $this->brandRegistry = $brandRegistry;
        $this->overlayRegistry = $overlayRegistry;
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order
            || !$this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())
            || !$order->getTwoOrderId()
        ) {
            return;
        }

        try {
            $this->orderService->cancelTwoOrder($order);
        } catch (LocalizedException $e) {
            // Already user-friendly — let it propagate so the admin sees
            // the API's reason. The Magento cancel will not be persisted.
            $this->logger->error(
                sprintf(
                    'Two cancel-sync failed for order %s: %s',
                    $order->getIncrementId(),
                    $e->getMessage()
                )
            );
            throw $e;
        } catch (\Throwable $e) {
            // Unexpected (network, bug). Wrap to keep the admin-facing
            // message clean while preserving the original for debugging.
            $this->logger->error(
                sprintf(
                    'Two cancel-sync errored unexpectedly for order %s: %s',
                    $order->getIncrementId(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            throw new LocalizedException(
                __(
                    'Could not synchronise the cancellation with %1. The order has not been cancelled. Please try again, or contact support if this persists.',
                    $this->brandRegistry->getProductName()
                ),
                $e
            );
        }
    }
}
