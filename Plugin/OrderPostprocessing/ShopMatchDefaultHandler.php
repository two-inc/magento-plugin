<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\OrderPostprocessing;

use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Api\OrderPostprocessingShopMatchInterface;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Service\Order\PostprocessingSubscribers;

/**
 * The plugin's default handler on the order postprocessing hook (TWO-26276):
 * runs the shop-match checks, unless another handler is registered on the
 * hook, which then owns them. Named PostprocessingSubscribers::DEFAULT_HANDLER
 * in etc/di.xml.
 */
class ShopMatchDefaultHandler
{
    /**
     * @var OrderPostprocessingShopMatchInterface
     */
    private $shopMatch;

    /**
     * @var PostprocessingSubscribers
     */
    private $subscribers;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        OrderPostprocessingShopMatchInterface $shopMatch,
        PostprocessingSubscribers $subscribers,
        LogRepository $logRepository
    ) {
        $this->shopMatch = $shopMatch;
        $this->subscribers = $subscribers;
        $this->logRepository = $logRepository;
    }

    /**
     * @param OrderPostprocessingInterface $subject
     * @param array $result
     * @param array $payload
     * @param array $context
     * @return array $result, unchanged
     * @throws ShopMatchRefusedException
     */
    public function afterProcess(
        OrderPostprocessingInterface $subject,
        array $result,
        array $payload,
        array $context
    ): array {
        if ($payload === []) {
            // A request with no body has nothing to check.
            return $result;
        }

        $handlers = $this->subscribers->merchantHandlers($subject);
        if ($handlers === []) {
            $this->shopMatch->check($result, $payload, $context);
            return $result;
        }

        $this->logRepository->addDebugLog('OrderPostprocessingShopMatchDelegated', [
            'request_type' => $context['request_type'] ?? null,
            'trigger' => $context['trigger'] ?? null,
            // Names the subject, so a support read finds the order (TWO-26309).
            'subject' => $this->subject($context),
            'handlers' => $handlers,
        ]);

        return $result;
    }

    /**
     * @param array $context
     * @return string|null `order <increment id>` or `quote <id>`
     */
    private function subject(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (is_object($order) && method_exists($order, 'getIncrementId') && $order->getIncrementId()) {
            return 'order ' . $order->getIncrementId();
        }
        $quote = $context['quote'] ?? null;

        return is_object($quote) && method_exists($quote, 'getId') && $quote->getId() ? 'quote ' . $quote->getId() : null;
    }
}
