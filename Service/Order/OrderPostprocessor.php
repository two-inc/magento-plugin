<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Throwable;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Service\Api\Adapter;

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

    /** Requests whose context carries Two's view of the order (TWO-26309). */
    private const READS_TWO_ORDER = [Hook::REQUEST_ORDER_UPDATE, Hook::REQUEST_REFUND];

    /** The change log (TWO-26309): at most this many entries, each string cut to MAX_DIFF_STRING bytes. */
    private const MAX_DIFF_ENTRIES = 40;
    private const MAX_DIFF_STRING = 120;

    /** Diff values outside these paths can carry buyer data, so only their path is logged. */
    private const DIFF_VALUE_PATHS = '#^/(line_items|tax_subtotals|partial/line_items|partial/tax_subtotals)(/|$)'
        . '|^(/partial)?/(gross_amount|net_amount|tax_amount|invoice_amount|payable_amount|prepaid_amount'
        . '|discount_amount|amount|currency)$#';

    /** Bounds the read of Two's order for the context, as the admin edit's own lookup is. */
    private const TWO_ORDER_READ_TIMEOUT_SECONDS = 10;

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
     * @var PostprocessingSubscribers
     */
    private $subscribers;

    /**
     * @var ShopMatchRefusals
     */
    private $refusals;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var TaxCodeResolver
     */
    private $taxCodeResolver;

    public function __construct(
        ComposeOrder $orderService,
        ConfigRepository $configRepository,
        LogRepository $logRepository,
        Hook $hook,
        PostprocessingSubscribers $subscribers,
        ShopMatchRefusals $refusals,
        Adapter $adapter,
        TaxCodeResolver $taxCodeResolver
    ) {
        $this->orderService = $orderService;
        $this->configRepository = $configRepository;
        $this->logRepository = $logRepository;
        $this->hook = $hook;
        $this->subscribers = $subscribers;
        $this->refusals = $refusals;
        $this->adapter = $adapter;
        $this->taxCodeResolver = $taxCodeResolver;
    }

    /**
     * Fire the hook for one outbound request and return the payload to send.
     *
     * @param string $requestType One of the Hook::REQUEST_* values.
     * @param array $payload The request body as composed.
     * @param array $context trigger, endpoint and the platform objects
     *                       (quote or order, invoice, shipment, creditmemo), and
     *                       `two_order`, Two's GET /v1/order/{id} response, where
     *                       the caller has already read it.
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
            if (!$this->refusals->issued($e)) {
                // One a subscriber made itself is a fault in its code, not the checks' verdict.
                throw $this->refusal(self::HOOK_FAILED, $context, $this->faultDetails($e));
            }
            // The default handler's refusal, or a subscriber's through the opt-in checks: not a hook bug.
            throw $e;
        } catch (Throwable $e) {
            // A non-array return lands here too, as the interface's return type fails.
            throw $this->refusal(self::HOOK_FAILED, $context, $this->faultDetails($e));
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
                $this->logIgnored(self::BODY_NOT_ACCEPTED, $context, $this->compactDiff($this->diff([], $result)));
            }
        } catch (Throwable $e) {
            $this->logIgnored(self::HOOK_FAILED, $context, $this->faultDetails($e));
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
        $fallback = $rate !== null && $this->configRepository->isShippingTaxFallbackEnabled($storeId);
        $order = $context['order'] ?? null;
        $twoOrderId = $order instanceof Order ? (string)$order->getTwoOrderId() : '';
        $twoOrder = $context['two_order'] ?? null;
        unset($context['two_order']);
        $readsTwoOrder = in_array($requestType, self::READS_TWO_ORDER, true);
        if ($readsTwoOrder && !is_array($twoOrder) && $twoOrderId !== ''
            && $this->subscribers->merchantHandlers($this->hook) !== []
        ) {
            $twoOrder = $this->readTwoOrder($twoOrderId, (int)$storeId);
        }
        $endpoint = (string)($context['endpoint'] ?? '');

        return array_merge(
            ['trigger' => ''],
            $context,
            [
                // The real path, as sent (TWO-26309).
                'endpoint' => $twoOrderId !== '' ? str_replace('{id}', $twoOrderId, $endpoint) : $endpoint,
                'request_type' => $requestType,
                'shipping_tax_rate' => $rate,
                'fallback_shipping_tax_rate' => $fallback ? $rate : null,
                'fallback_shipping_tax_code' => $fallback ? $this->fallbackShippingTaxCode($subject) : null,
                'contract_version' => Hook::CONTRACT_VERSION,
                'order_lines' => $readsTwoOrder ? TwoOrderView::lines($twoOrder) : null,
                'order_refunds' => $readsTwoOrder ? TwoOrderView::refunds($twoOrder) : null,
                'order_tenders' => $this->orderService->getTenders($this->tenderSubject($requestType, $context)),
            ]
        );
    }

    /**
     * The entity whose tenders the request carries: the credit memo a refund
     * refunds, the invoice a capture fulfils, else the order (or, for the
     * intent, the order built from the cart).
     *
     * @param string $requestType
     * @param array $context
     * @return object|null
     */
    private function tenderSubject(string $requestType, array $context)
    {
        if ($requestType === Hook::REQUEST_REFUND && isset($context['creditmemo'])) {
            return $context['creditmemo'];
        }
        if ($requestType === Hook::REQUEST_CAPTURE && isset($context['invoice'])) {
            return $context['invoice'];
        }

        return $context['intent_order'] ?? $context['order'] ?? $context['quote'] ?? null;
    }

    /**
     * Two's view of the order for the context; null when it cannot be read.
     *
     * @param string $twoOrderId
     * @param int $storeId
     * @return array|null
     */
    private function readTwoOrder(string $twoOrderId, int $storeId): ?array
    {
        try {
            $response = $this->adapter->execute(
                '/v1/order/' . $twoOrderId,
                [],
                'GET',
                $storeId,
                null,
                null,
                self::TWO_ORDER_READ_TIMEOUT_SECONDS
            );
        } catch (Throwable $e) {
            return null;
        }

        return is_array($response) && !isset($response['error_code']) && isset($response['line_items'])
            ? $response
            : null;
    }

    /**
     * The context's fallback_shipping_tax_code: the code steps 1 to 3 of the
     * tax codes for 0% lines give a 0% shipping line taxed by core's shipping
     * tax class at the subject's addresses. Null when that class is None,
     * the rate there is not 0%, the row is (none), or the codes cannot be read.
     *
     * @param mixed $subject
     * @return string|null
     */
    private function fallbackShippingTaxCode($subject): ?string
    {
        if (!$subject instanceof Order) {
            return null;
        }
        try {
            return $this->taxCodeResolver->shippingFallbackCode($subject);
        } catch (Throwable $e) {
            return null;
        }
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
     * Log the change for support, with Debug Mode on (TWO-26309): the request,
     * the merchant handlers and the number of changes, with at most
     * MAX_DIFF_ENTRIES of them and values that can carry buyer data withheld.
     *
     * @param array $context
     * @param array $diff
     * @return void
     */
    private function recordChange(array $context, array $diff): void
    {
        $subject = $context['order'] ?? $context['quote'] ?? null;
        if (!$this->configRepository->isDebugMode($subject ? (int)$subject->getStoreId() : null)) {
            return;
        }
        $this->logRepository->addDebugLog('OrderPostprocessingChanged', [
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
        ] + $this->compactDiff($diff));
    }

    /**
     * What the subscribers changed, for a log line: values outside the amount,
     * line and tax subtotal paths withheld, long strings cut.
     *
     * @param array $diff from diff()
     * @return array{subscribers: string[], changes: int, diff: array}
     */
    public function compactDiff(array $diff): array
    {
        $entries = [];
        foreach (array_slice($diff, 0, self::MAX_DIFF_ENTRIES) as $entry) {
            $keep = preg_match(self::DIFF_VALUE_PATHS, $entry['path']) === 1;
            $entries[] = [
                'path' => self::cut($entry['path']),
                'before' => $keep ? self::loggable($entry['before']) : ($entry['before'] === null ? null : 'redacted'),
                'after' => $keep ? self::loggable($entry['after']) : ($entry['after'] === null ? null : 'redacted'),
            ];
        }

        return [
            'subscribers' => $this->subscribers->merchantHandlers($this->hook),
            'changes' => count($diff),
            'diff' => $entries,
        ];
    }

    /**
     * A subscriber's fault for the log (TWO-26309): its class and where it was
     * thrown, never its message, which can carry buyer data.
     *
     * @param Throwable $e
     * @return array{exception: string, at: string}
     */
    private function faultDetails(Throwable $e): array
    {
        return ['exception' => get_class($e), 'at' => self::throwSite($e)];
    }

    /**
     * Where an exception was thrown, relative to the Magento root or this
     * module; any other path is cut to its file name.
     *
     * @param Throwable $e
     * @return string
     */
    public static function throwSite(Throwable $e): string
    {
        $file = str_replace('\\', '/', $e->getFile());
        $roots = [defined('BP') ? (string)BP : '', dirname(__DIR__, 2)];
        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if ($root !== '/' && strpos($file, $root) === 0) {
                return substr($file, strlen($root)) . ':' . $e->getLine();
            }
        }

        return basename($file) . ':' . $e->getLine();
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function loggable($value)
    {
        if (is_array($value) || is_object($value)) {
            return self::cut((string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return is_string($value) ? self::cut($value) : $value;
    }

    private static function cut(string $value): string
    {
        return strlen($value) <= self::MAX_DIFF_STRING
            ? $value
            : mb_strcut($value, 0, self::MAX_DIFF_STRING, 'UTF-8') . '...';
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
