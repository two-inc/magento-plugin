<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollection;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory as OrderTaxCollectionFactory;
use Throwable;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;

/**
 * The one place every order request passes through immediately before it is
 * sent (TWO-26092): fires the postprocessing hook, runs the plugin's
 * consistency gates on what the hook returned, and records any change.
 *
 * Extends Service\Order only to reuse its line tax gate and its shipping tax
 * rate resolver.
 */
class OrderPostprocessor extends OrderService
{
    public const CODE_PREFIX_CHANGED = 'TWO_ORDER_POSTPROCESSING_';
    public const CODE_PREFIX_UNCHANGED = 'TWO_ORDER_';

    public const HOOK_FAILED = 'HOOK_FAILED';
    public const BODY_NOT_ACCEPTED = 'BODY_NOT_ACCEPTED';
    public const LINE_INCONSISTENT = 'LINE_INCONSISTENT';
    public const SUBTOTALS_INCONSISTENT = 'SUBTOTALS_INCONSISTENT';
    public const TOTALS_INCONSISTENT = 'TOTALS_INCONSISTENT';

    /** Requests the API defines no body for. */
    private const BODYLESS = [Hook::REQUEST_ORDER_CONFIRM, Hook::REQUEST_CANCEL];

    /** Requests whose refusal reaches the buyer rather than the merchant. */
    private const BUYER_FACING = [Hook::REQUEST_ORDER_INTENT, Hook::REQUEST_ORDER_CREATE];

    /** One cent, plus float noise: independently rounded net and tax may sum a cent off their gross. */
    private const CENT_TOLERANCE = 0.010001;

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
        Image $imageHelper,
        ConfigRepository $configRepository,
        CategoryCollection $categoryCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        Emulation $appEmulation,
        Url $url,
        LogRepository $logRepository,
        FeeLineProviderPool $feeLineProviderPool,
        OrderTaxManagementInterface $orderTaxManagement,
        TaxCalculation $taxCalculation,
        OrderTaxCollectionFactory $orderTaxCollectionFactory,
        GroupRepositoryInterface $groupRepository,
        Hook $hook,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
        parent::__construct(
            $imageHelper,
            $configRepository,
            $categoryCollectionFactory,
            $orderItemRepository,
            $appEmulation,
            $url,
            $logRepository,
            $feeLineProviderPool,
            $orderTaxManagement,
            $taxCalculation,
            $orderTaxCollectionFactory,
            $groupRepository
        );
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
     * @throws LocalizedException when the hook throws or a gate fails
     */
    public function process(string $requestType, array $payload, array $context): array
    {
        $context = $this->buildContext($requestType, $context);

        try {
            $result = $this->hook->process($payload, $context);
        } catch (Throwable $e) {
            throw $this->refusal(self::HOOK_FAILED, true, $context, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], []);
        }

        $diff = $this->diff($payload, $result);
        $changed = $diff !== [];
        $this->gate($result, $changed, $diff, $context);

        if ($changed) {
            $this->recordChange($context, $diff);
        }

        return $result;
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
            return $this->resolveShippingTaxRateForClass($taxClassId, $subject, $storeId);
        } catch (Throwable $e) {
            $this->logRepository->addDebugLog('OrderPostprocessingRateUnresolved', $e->getMessage());
            return null;
        }
    }

    /**
     * Every consistency gate, on the payload that is about to be sent.
     *
     * @param array $payload
     * @param bool $changed
     * @param array $diff
     * @param array $context
     * @return void
     * @throws LocalizedException
     */
    private function gate(array $payload, bool $changed, array $diff, array $context): void
    {
        if (in_array($context['request_type'], self::BODYLESS, true)) {
            if ($payload !== []) {
                throw $this->refusal(self::BODY_NOT_ACCEPTED, $changed, $context, [], $diff);
            }
            return;
        }

        foreach (PostprocessingTotals::lineBlockKeys($payload) as $key) {
            $block = $key === '' ? $payload : $payload[$key];
            $this->gateLines($block['line_items'], $changed, $diff, $context);
            // Totals before subtotals: forgotten totals are the commoner slip, and the one worth naming.
            $this->gateTotals($block, $changed, $diff, $context);
            $this->gateSubtotals($block, $changed, $diff, $context);
        }
    }

    /**
     * G3: each line's net, tax and gross agree, and its tax follows from its
     * declared rate.
     *
     * @throws LocalizedException
     */
    private function gateLines(array $lines, bool $changed, array $diff, array $context): void
    {
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw $this->refusal(self::LINE_INCONSISTENT, $changed, $context, ['line' => $index], $diff);
            }
            $net = (float)($line['net_amount'] ?? 0);
            $tax = (float)($line['tax_amount'] ?? 0);
            $gross = (float)($line['gross_amount'] ?? 0);
            if (abs($net + $tax - $gross) > self::CENT_TOLERANCE) {
                throw $this->refusal(self::LINE_INCONSISTENT, $changed, $context, [
                    'line' => $line['order_item_id'] ?? $index,
                    'net_amount' => $net,
                    'tax_amount' => $tax,
                    'gross_amount' => $gross,
                ], $diff);
            }
        }

        try {
            $this->validateTaxReconciliation($lines);
        } catch (LocalizedException $e) {
            if (!$changed && in_array($context['request_type'], self::BUYER_FACING, true)) {
                throw $e;
            }
            throw $this->refusal(self::LINE_INCONSISTENT, $changed, $context, ['tax_reconciliation' => 'failed'], $diff);
        }
    }

    /**
     * G4: every tax_subtotals bucket equals the lines at its rate.
     *
     * @throws LocalizedException
     */
    private function gateSubtotals(array $block, bool $changed, array $diff, array $context): void
    {
        if (!isset($block['tax_subtotals']) || !is_array($block['tax_subtotals'])) {
            return;
        }

        $declared = [];
        foreach ($block['tax_subtotals'] as $subtotal) {
            if (!is_array($subtotal)) {
                throw $this->refusal(self::SUBTOTALS_INCONSISTENT, $changed, $context, ['subtotal' => $subtotal], $diff);
            }
            $rate = PostprocessingTotals::amount($subtotal['tax_rate'] ?? 0, 6);
            $declared[$rate]['taxable_amount'] = ($declared[$rate]['taxable_amount'] ?? 0.0)
                + (float)($subtotal['taxable_amount'] ?? 0);
            $declared[$rate]['tax_amount'] = ($declared[$rate]['tax_amount'] ?? 0.0)
                + (float)($subtotal['tax_amount'] ?? 0);
        }
        $expected = PostprocessingTotals::sumByRate($block['line_items']);

        foreach (array_unique(array_merge(array_keys($declared), array_keys($expected))) as $rate) {
            foreach (['taxable_amount', 'tax_amount'] as $field) {
                $want = round($expected[$rate][$field] ?? 0.0, 2);
                $have = round($declared[$rate][$field] ?? 0.0, 2);
                if (abs($want - $have) > 0.0001) {
                    throw $this->refusal(self::SUBTOTALS_INCONSISTENT, $changed, $context, [
                        'tax_rate' => (string)$rate,
                        'field' => $field,
                        'declared' => $have,
                        'sum_of_lines' => $want,
                    ], $diff);
                }
            }
        }
    }

    /**
     * G5: totals equal the sum of the lines and gross = net + tax. G6: a
     * refund's amount equals the sum of its line gross.
     *
     * Tolerance is the count-scaled rounding epsilon reconcileOtherCharges()
     * already applies to the same comparison.
     *
     * @throws LocalizedException
     */
    private function gateTotals(array $block, bool $changed, array $diff, array $context): void
    {
        $lines = $block['line_items'];
        $epsilon = min(max(0.01, 0.005 * count($lines)), self::OTHER_CHARGES_EPSILON_CEILING) + 0.000001;
        $sums = ['net_amount' => 0.0, 'tax_amount' => 0.0, 'gross_amount' => 0.0];
        foreach ($lines as $line) {
            foreach (array_keys($sums) as $field) {
                $sums[$field] += (float)($line[$field] ?? 0);
            }
        }

        $checks = [];
        if (array_key_exists('amount', $block)) {
            $checks['amount'] = [(float)$block['amount'], $sums['gross_amount'], $epsilon];
        }
        foreach (array_keys($sums) as $field) {
            if (array_key_exists($field, $block)) {
                $checks[$field] = [(float)$block[$field], $sums[$field], $epsilon];
            }
        }
        if (isset($checks['gross_amount'], $checks['net_amount'], $checks['tax_amount'])) {
            $checks['gross = net + tax'] = [
                $checks['gross_amount'][0],
                $checks['net_amount'][0] + $checks['tax_amount'][0],
                self::CENT_TOLERANCE,
            ];
        }

        foreach ($checks as $name => [$declared, $expected, $tolerance]) {
            if (abs($declared - $expected) > $tolerance) {
                throw $this->refusal(self::TOTALS_INCONSISTENT, $changed, $context, [
                    'check' => $name,
                    'declared' => round($declared, 2),
                    'expected' => round($expected, 2),
                    'tolerance' => round($tolerance, 2),
                ], $diff);
            }
        }
    }

    /**
     * Log the refusal and build the exception that stops the request.
     */
    private function refusal(
        string $gate,
        bool $changed,
        array $context,
        array $details,
        array $diff
    ): LocalizedException {
        $code = ($changed ? self::CODE_PREFIX_CHANGED : self::CODE_PREFIX_UNCHANGED) . $gate;
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
