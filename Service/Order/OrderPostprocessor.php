<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Throwable;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Exception\ShopMatchRefusedException;

/**
 * Every order request passes through here just before it is sent (TWO-26092). A subscriber's
 * result is sent as returned; only a fault in the subscriber's code refuses it, or a shop-match
 * refusal from the checks it opted back into. Whether the payload adds up is for Two's API to
 * validate (TWO-26284). The shop-match checks belong to the hook's default handler, which stands
 * down for a subscriber (TWO-26276).
 */
class OrderPostprocessor
{
    public const CODE_PREFIX = 'TWO_ORDER_POSTPROCESSING_';

    public const HOOK_FAILED = 'HOOK_FAILED';
    public const BODY_NOT_ACCEPTED = 'BODY_NOT_ACCEPTED';

    /** Requests the API defines no body for. */
    private const BODYLESS = [Hook::REQUEST_ORDER_CONFIRM, Hook::REQUEST_CANCEL];

    /** Requests whose refusal reaches the buyer rather than the merchant. */
    private const BUYER_FACING = [Hook::REQUEST_ORDER_INTENT, Hook::REQUEST_ORDER_CREATE];

    /**
     * @var ComposeOrder
     */
    private $orderService;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var Hook
     */
    private $hook;

    /**
     * @var HistoryFactory
     */
    private $historyFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $historyRepository;

    public function __construct(
        ComposeOrder $orderService,
        ConfigRepository $configRepository,
        LogRepository $logRepository,
        Hook $hook,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
        $this->orderService = $orderService;
        $this->configRepository = $configRepository;
        $this->logRepository = $logRepository;
        $this->hook = $hook;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
    }

    /**
     * Fire the hook for one outbound request and return the payload to send.
     *
     * @param string $requestType One of the Hook::REQUEST_* values.
     * @param array $payload The request body as composed.
     * @param array $context trigger, endpoint and the platform objects
     *                       (quote or order, invoice, shipment, creditmemo).
     * @return array
     * @throws LocalizedException when a shop-match check refuses, or the hook throws or returns what
     *                            cannot be sent on a request with a body
     */
    public function process(string $requestType, array $payload, array $context): array
    {
        $context = $this->buildContext($requestType, $context);
        if (in_array($requestType, self::BODYLESS, true)) {
            return $this->processBodyless($context);
        }

        try {
            $result = $this->hook->process($payload, $context);
        } catch (ShopMatchRefusedException $e) {
            // The default handler's refusal, or a subscriber's through the opt-in checks: not a hook bug.
            throw $e;
        } catch (Throwable $e) {
            // A non-array return lands here too, as the interface's return type fails.
            throw $this->refusal(self::HOOK_FAILED, $context, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        $diff = $this->diff($payload, $result);
        if ($diff === []) {
            // Byte for byte as composed, even if a subscriber only reordered keys.
            return $payload;
        }
        if (json_encode($result) === false) {
            // The adapter would send an empty body: a bug in the subscriber, not a declaration.
            throw $this->refusal(self::HOOK_FAILED, $context, ['json_error' => json_last_error_msg()]);
        }
        $this->recordChange($context, $diff);

        return $result;
    }

    /**
     * Sent empty whatever the subscriber does: Magento has already acted, and a live Two order could be invoiced.
     *
     * @param array $context
     * @return array
     */
    private function processBodyless(array $context): array
    {
        try {
            $result = $this->hook->process([], $context);
            if ($result !== []) {
                $this->logIgnored(self::BODY_NOT_ACCEPTED, $context, ['diff' => $this->diff([], $result)]);
            }
        } catch (Throwable $e) {
            $this->logIgnored(self::HOOK_FAILED, $context, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * The hook's context: what the caller knows, plus what every request carries.
     *
     * @param string $requestType
     * @param array $context
     * @return array
     */
    private function buildContext(string $requestType, array $context): array
    {
        $subject = $context['order'] ?? $context['quote'] ?? null;
        $rate = $subject ? $this->configuredShippingTaxRate($subject) : null;
        $storeId = $subject ? (int)$subject->getStoreId() : null;

        return array_merge(
            ['trigger' => '', 'endpoint' => ''],
            $context,
            [
                'request_type' => $requestType,
                'shipping_tax_rate' => $rate,
                'fallback_shipping_tax_rate' => $rate !== null
                    && $this->configRepository->isShippingTaxFallbackEnabled($storeId) ? $rate : null,
                'contract_version' => Hook::CONTRACT_VERSION,
            ]
        );
    }

    /**
     * The rate core's shipping tax class applies at the subject's tax
     * address, whether or not the shipping line was taxed. Null when no class
     * is configured or the rate cannot be resolved.
     *
     * @param Order|\Magento\Quote\Model\Quote $subject
     * @return float|null
     */
    public function configuredShippingTaxRate($subject): ?float
    {
        $storeId = (int)$subject->getStoreId();
        $taxClassId = $this->configRepository->getShippingTaxClassId($storeId);
        if ($taxClassId === null) {
            return null;
        }

        try {
            return $this->orderService->resolveShippingTaxRateForClass($taxClassId, $subject, $storeId);
        } catch (Throwable $e) {
            $this->logRepository->addDebugLog('OrderPostprocessingRateUnresolved', $e->getMessage());
            return null;
        }
    }

    /**
     * Log the refusal and build the exception that stops the request.
     */
    private function refusal(string $gate, array $context, array $details): LocalizedException
    {
        $code = self::CODE_PREFIX . $gate;
        $this->logRepository->addErrorLog('OrderPostprocessingRefused', [
            'code' => $code,
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
            'details' => $details,
        ]);

        if (in_array($context['request_type'], self::BUYER_FACING, true)) {
            return new LocalizedException(__('This order could not be placed. Please contact the merchant.'));
        }
        return new LocalizedException(
            __('This request was not sent (%1). See the payment log for the details.', $code)
        );
    }

    /**
     * Log what a subscriber did to a body-less request, which is sent empty regardless.
     */
    private function logIgnored(string $gate, array $context, array $details): void
    {
        $this->logRepository->addErrorLog('OrderPostprocessingIgnored', [
            'code' => self::CODE_PREFIX . $gate,
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
            'details' => $details,
        ]);
    }

    /**
     * Log the change for support, and note it on the order where there is one.
     *
     * @param array $context
     * @param array $diff
     * @return void
     */
    private function recordChange(array $context, array $diff): void
    {
        $diff = array_combine(
            array_column($diff, 'path'),
            array_map(static fn (array $change): array => [$change['before'], $change['after']], $diff)
        );
        $this->logRepository->addDebugLog('OrderPostprocessingChanged', [
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
            'diff' => $diff,
        ]);

        $order = $context['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }
        $comment = __(
            'Order postprocessing changed the %1 request: %2',
            $context['request_type'],
            json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        )->render();

        if (!$order->getEntityId()) {
            // Not saved yet: the comment is written with the order.
            $order->addCommentToStatusHistory($comment);
            return;
        }
        try {
            $history = $this->historyFactory->create();
            $history->setParentId($order->getEntityId())
                ->setComment($comment)
                ->setEntityName('order')
                ->setStatus($order->getStatus());
            $this->historyRepository->save($history);
        } catch (Throwable $e) {
            $this->logRepository->addErrorLog('OrderPostprocessingCommentFailed', $e->getMessage());
        }
    }

    /**
     * Leaf-level differences keyed by JSON pointer.
     *
     * @param mixed $before
     * @param mixed $after
     * @param string $path
     * @return array<int, array{path: string, before: mixed, after: mixed}>
     */
    public function diff($before, $after, string $path = ''): array
    {
        if (is_array($before) && is_array($after)) {
            $diff = [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after)), SORT_REGULAR) as $key) {
                $childPath = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
                if (!array_key_exists($key, $before)) {
                    $diff[] = ['path' => $childPath, 'before' => null, 'after' => $after[$key]];
                } elseif (!array_key_exists($key, $after)) {
                    $diff[] = ['path' => $childPath, 'before' => $before[$key], 'after' => null];
                } else {
                    $diff = array_merge($diff, $this->diff($before[$key], $after[$key], $childPath));
                }
            }
            return $diff;
        }

        return $before === $after ? [] : [['path' => $path, 'before' => $before, 'after' => $after]];
    }
}
