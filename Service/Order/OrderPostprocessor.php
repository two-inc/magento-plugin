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

/**
 * Every order request passes through here just before it is sent (TWO-26092). An unchanged
 * request is sent as composed, behind only the gates it had before the hook existed.
 */
class OrderPostprocessor
{
    public const CODE_PREFIX = 'TWO_ORDER_POSTPROCESSING_';

    public const HOOK_FAILED = 'HOOK_FAILED';
    public const BODY_NOT_ACCEPTED = 'BODY_NOT_ACCEPTED';
    public const LINE_INCONSISTENT = 'LINE_INCONSISTENT';
    public const SUBTOTALS_INCONSISTENT = 'SUBTOTALS_INCONSISTENT';
    public const TOTALS_INCONSISTENT = 'TOTALS_INCONSISTENT';

    /** Requests the API defines no body for. */
    private const BODYLESS = [Hook::REQUEST_ORDER_CONFIRM, Hook::REQUEST_CANCEL];

    /** Requests whose refusal reaches the buyer rather than the merchant. */
    private const BUYER_FACING = [Hook::REQUEST_ORDER_INTENT, Hook::REQUEST_ORDER_CREATE];

    /** Composed by ComposeOrder, which ran the line tax gate before the hook existed. */
    private const LINE_TAX_GATED = [Hook::REQUEST_ORDER_CREATE, Hook::REQUEST_ORDER_UPDATE];

    /** One cent, plus float noise: independently rounded net and tax may sum a cent off their gross. */
    private const CENT_TOLERANCE = 0.010001;

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
     * @throws LocalizedException when a gate fails, or the hook throws on a request with a body
     */
    public function process(string $requestType, array $payload, array $context): array
    {
        if (in_array($requestType, self::LINE_TAX_GATED, true)) {
            $this->orderService->validateTaxReconciliation($payload['line_items'] ?? []);
        }

        $context = $this->buildContext($requestType, $context);
        if (in_array($requestType, self::BODYLESS, true)) {
            return $this->processBodyless($context);
        }

        try {
            $result = $this->hook->process($payload, $context);
        } catch (Throwable $e) {
            throw $this->refusal(self::HOOK_FAILED, $context, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], []);
        }

        $diff = $this->diff($payload, $result);
        if ($diff === []) {
            // Byte for byte as composed, even if a subscriber only reordered keys.
            return $payload;
        }
        $this->gate($payload, $result, $diff, $context);
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
     * Gates on a changed payload, judging only the change: edited or added lines, and residual drift.
     *
     * @param array $before The payload as composed.
     * @param array $after The payload the subscriber returned.
     * @param array $diff
     * @param array $context
     * @return void
     * @throws LocalizedException
     */
    private function gate(array $before, array $after, array $diff, array $context): void
    {
        $composedKeys = PostprocessingTotals::lineBlockKeys($before);
        $keys = array_unique(array_merge($composedKeys, PostprocessingTotals::lineBlockKeys($after)));
        foreach ($keys as $key) {
            $block = $key === '' ? $after : $after[$key] ?? null;
            $original = $key === '' ? $before : $before[$key] ?? [];
            $original = is_array($original) ? $original : [];
            $originalLines = is_array($original['line_items'] ?? null) ? $original['line_items'] : [];

            // A block the plugin composed must survive, or deleting it would escape every check below.
            if (in_array($key, $composedKeys, true)) {
                $this->gatePresence($key, $block, $original, $diff, $context);
            }

            $this->gateLines($block['line_items'], $originalLines, $diff, $context);
            // Totals before subtotals: forgotten totals are the commoner slip, and the one worth naming.
            $this->gateResiduals(self::TOTALS_INCONSISTENT, $this->totalsResiduals($block), $this->totalsResiduals($original), $diff, $context);
            $this->gateResiduals(self::SUBTOTALS_INCONSISTENT, $this->subtotalsResiduals($block), $this->subtotalsResiduals($original), $diff, $context);
        }
    }

    /**
     * A composed block still carries its lines, and its subtotals if it had them.
     *
     * @param string $key
     * @param mixed $block
     * @param array $original
     * @param array $diff
     * @param array $context
     * @throws LocalizedException
     */
    private function gatePresence(string $key, $block, array $original, array $diff, array $context): void
    {
        $where = $key === '' ? '' : '/' . $key;
        if (!is_array($block) || !is_array($block['line_items'] ?? null)) {
            throw $this->refusal(self::TOTALS_INCONSISTENT, $context, [
                'missing' => $where . '/line_items',
            ], $diff);
        }
        if (is_array($original['tax_subtotals'] ?? null) && !is_array($block['tax_subtotals'] ?? null)) {
            throw $this->refusal(self::SUBTOTALS_INCONSISTENT, $context, [
                'missing' => $where . '/tax_subtotals',
            ], $diff);
        }
    }

    /**
     * G3: each changed or added line's net, tax and gross agree, and its tax
     * follows from its declared rate.
     *
     * @throws LocalizedException
     */
    private function gateLines(array $lines, array $originalLines, array $diff, array $context): void
    {
        $changedLines = [];
        foreach ($lines as $index => $line) {
            if (in_array($line, $originalLines, true)) {
                continue;
            }
            if (!is_array($line)) {
                throw $this->refusal(self::LINE_INCONSISTENT, $context, ['line' => $index], $diff);
            }
            $net = (float)($line['net_amount'] ?? 0);
            $tax = (float)($line['tax_amount'] ?? 0);
            $gross = (float)($line['gross_amount'] ?? 0);
            if (abs($net + $tax - $gross) > self::CENT_TOLERANCE) {
                throw $this->refusal(self::LINE_INCONSISTENT, $context, [
                    'line' => $line['order_item_id'] ?? $index,
                    'net_amount' => $net,
                    'tax_amount' => $tax,
                    'gross_amount' => $gross,
                ], $diff);
            }
            $changedLines[] = $line;
        }

        try {
            $this->orderService->validateTaxReconciliation($changedLines);
        } catch (LocalizedException $e) {
            throw $this->refusal(self::LINE_INCONSISTENT, $context, [
                'tax_reconciliation' => 'failed',
                'message' => $e->getMessage(),
            ], $diff);
        }
    }

    /**
     * Refuse when a residual moved from what the composed payload carried.
     *
     * @param string $gate
     * @param array<string, float> $residuals
     * @param array<string, float> $originalResiduals
     * @param array $diff
     * @param array $context
     * @throws LocalizedException
     */
    private function gateResiduals(
        string $gate,
        array $residuals,
        array $originalResiduals,
        array $diff,
        array $context
    ): void {
        foreach ($residuals as $check => $residual) {
            $original = $originalResiduals[$check] ?? 0.0;
            if (abs($residual - $original) > self::CENT_TOLERANCE) {
                throw $this->refusal($gate, $context, [
                    'check' => $check,
                    'residual' => round($residual, 2),
                    'residual_before_hook' => round($original, 2),
                ], $diff);
            }
        }
    }

    /**
     * G5 and G6: each total less the sum of its lines, and gross less net + tax.
     *
     * @param array $block
     * @return array<string, float>
     */
    private function totalsResiduals(array $block): array
    {
        if (!is_array($block['line_items'] ?? null)) {
            return [];
        }
        $sums = ['net_amount' => 0.0, 'tax_amount' => 0.0, 'gross_amount' => 0.0];
        foreach ($block['line_items'] as $line) {
            foreach (array_keys($sums) as $field) {
                $sums[$field] += is_array($line) ? (float)($line[$field] ?? 0) : 0.0;
            }
        }

        $residuals = [];
        if (array_key_exists('amount', $block)) {
            $residuals['amount'] = (float)$block['amount'] - $sums['gross_amount'];
        }
        foreach ($sums as $field => $sum) {
            if (array_key_exists($field, $block)) {
                $residuals[$field] = (float)$block[$field] - $sum;
            }
        }
        if (isset($residuals['gross_amount'], $residuals['net_amount'], $residuals['tax_amount'])) {
            $residuals['gross = net + tax'] = (float)$block['gross_amount']
                - (float)$block['net_amount'] - (float)$block['tax_amount'];
        }

        return $residuals;
    }

    /**
     * G4: each tax_subtotals bucket less the lines at its rate.
     *
     * @param array $block
     * @return array<string, float>
     */
    private function subtotalsResiduals(array $block): array
    {
        if (!is_array($block['tax_subtotals'] ?? null) || !is_array($block['line_items'] ?? null)) {
            return [];
        }

        $residuals = [];
        foreach ($block['tax_subtotals'] as $index => $subtotal) {
            if (!is_array($subtotal)) {
                $residuals["subtotal $index"] = INF;
                continue;
            }
            $rate = PostprocessingTotals::amount($subtotal['tax_rate'] ?? 0, 6);
            foreach (['taxable_amount', 'tax_amount'] as $field) {
                $residuals["$rate $field"] = ($residuals["$rate $field"] ?? 0.0) + (float)($subtotal[$field] ?? 0);
            }
        }
        $lines = array_filter($block['line_items'], 'is_array');
        foreach (PostprocessingTotals::sumByRate($lines) as $rate => $sums) {
            foreach ($sums as $field => $sum) {
                $residuals["$rate $field"] = ($residuals["$rate $field"] ?? 0.0) - $sum;
            }
        }

        return $residuals;
    }

    /**
     * Log the refusal and build the exception that stops the request.
     */
    private function refusal(string $gate, array $context, array $details, array $diff): LocalizedException
    {
        $code = self::CODE_PREFIX . $gate;
        $this->logRepository->addErrorLog('OrderPostprocessingRefused', [
            'code' => $code,
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
            'details' => $details,
            'diff' => $diff,
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
     * Log the change, and note it on the order where there is one.
     *
     * @param array $context
     * @param array $diff
     * @return void
     */
    private function recordChange(array $context, array $diff): void
    {
        $this->logRepository->addLog('OrderPostprocessingChanged', [
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
