<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use InvalidArgumentException;
use LogicException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Api\OrderPostprocessingShopMatchInterface;
use Two\Gateway\Exception\ShopMatchRefusedException;

/**
 * The shop-match checks (TWO-26276, TWO-26309): whether what the plugin built
 * from the shop's figures matches the shop.
 *
 * On single lines, each while the payload still carries the line the plugin
 * built unchanged:
 * - a shipping line whose rate the shipping tax fallback supplied must carry
 *   the tax Magento charged at that rate (TWO-26117), on intent, create,
 *   update and capture;
 * - every other line's tax must reconcile with its declared rate and net, on
 *   create and update: the line is Magento's own figures, so a mismatch means
 *   the shop's tax does not match the rate it declared.
 *
 * On the whole order (scope SCOPE_ALL only): the payload's lines must add up
 * to the order's totals plus its tenders, gross, net and tax each, on intent
 * (logged only: the create checks it again), create and update.
 *
 * Refused in the buyer's wording on intent and create, the merchant's on
 * update and capture.
 */
class ShopMatchChecks implements OrderPostprocessingShopMatchInterface
{
    private const SHIPPING_LINE = 'shipping';

    /** The least tolerance of the line and whole-order checks, in currency units. */
    private const TOLERANCE = 0.02;

    /**
     * Per-unit component of the line check's tolerance, for the "Unit Price"
     * tax algorithm: Magento rounds each unit's tax and sums.
     */
    private const UNIT_ROUNDING_TOLERANCE = 0.005;

    /** The declared rate goes on the wire at 6dp, worth more than a cent on a large net. */
    private const RATE_PRECISION_TOLERANCE = 0.0000005;

    /** Requests the per-line declared-rate check applies to. */
    private const LINE_RATE_REQUESTS = [Hook::REQUEST_ORDER_CREATE, Hook::REQUEST_ORDER_UPDATE];

    /** Requests the whole-order check applies to. */
    private const WHOLE_ORDER_REQUESTS = [
        Hook::REQUEST_ORDER_INTENT,
        Hook::REQUEST_ORDER_CREATE,
        Hook::REQUEST_ORDER_UPDATE,
    ];

    /**
     * @var ComposeOrder
     */
    private $composeOrder;

    /**
     * @var ComposeCapture
     */
    private $composeCapture;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var ShopMatchRefusals
     */
    private $refusals;

    public function __construct(
        ComposeOrder $composeOrder,
        ComposeCapture $composeCapture,
        LogRepository $logRepository,
        ShopMatchRefusals $refusals
    ) {
        $this->composeOrder = $composeOrder;
        $this->composeCapture = $composeCapture;
        $this->logRepository = $logRepository;
        $this->refusals = $refusals;
    }

    /**
     * @inheritDoc
     * @throws LogicException when the context lacks the order or invoice a line was built from
     */
    public function check(array $result, array $payload, array $context, string $scope = self::SCOPE_ALL): void
    {
        if ($scope !== self::SCOPE_ALL && $scope !== self::SCOPE_LINES) {
            throw new InvalidArgumentException(sprintf('Unknown shop-match scope "%s".', $scope));
        }
        try {
            $this->checkShippingLine($result, $payload, $context);
            $this->checkLineRates($result, $payload, $context);
            if ($scope === self::SCOPE_ALL) {
                $this->checkWholeOrder($result, $context);
            }
        } catch (ShopMatchRefusedException $e) {
            throw $this->refusals->record($e);
        }
    }

    /**
     * @param array $result
     * @param array $payload
     * @param array $context
     * @return void
     */
    private function checkShippingLine(array $result, array $payload, array $context): void
    {
        $requestType = (string)($context['request_type'] ?? '');
        $composed = $this->shippingLine($this->lines($payload, $requestType));
        if ($composed === null || !$this->carries($this->lines($result, $requestType), $composed)) {
            return;
        }

        switch ($requestType) {
            case Hook::REQUEST_ORDER_INTENT:
                $entity = $context['intent_order'] ?? null;
                $service = $this->composeOrder;
                break;
            case Hook::REQUEST_ORDER_CREATE:
            case Hook::REQUEST_ORDER_UPDATE:
                $entity = $context['order'] ?? null;
                $service = $this->composeOrder;
                break;
            case Hook::REQUEST_CAPTURE:
                // An invoice composes its own shipping line; a shipment composes the order's.
                $entity = ($context['invoice'] ?? null) instanceof Invoice ? $context['invoice'] : ($context['order'] ?? null);
                $service = $this->composeCapture;
                break;
            default:
                // A refund relays its parent line's rate unchecked; the rest carry no lines.
                return;
        }

        if (!$entity instanceof Order && !$entity instanceof Invoice) {
            // Fail closed: a check that cannot see the shop must not pass. Under the hook this is HOOK_FAILED.
            throw new LogicException(sprintf(
                'The %s context carries no order to check the shipping line against.',
                $requestType
            ));
        }
        $service->assertShippingTaxFallback($entity);
    }

    /**
     * Each line the plugin built from Magento's figures, other than shipping,
     * while the result still carries it: its tax against its declared rate
     * and net (or, under "apply tax before discount", net plus discount).
     *
     * @param array $result
     * @param array $payload
     * @param array $context
     * @return void
     * @throws ShopMatchRefusedException
     */
    private function checkLineRates(array $result, array $payload, array $context): void
    {
        $requestType = (string)($context['request_type'] ?? '');
        if (!in_array($requestType, self::LINE_RATE_REQUESTS, true)) {
            return;
        }
        $kept = $this->lines($result, $requestType);
        foreach ($this->lines($payload, $requestType) as $line) {
            if (!is_array($line) || ($line['order_item_id'] ?? null) === self::SHIPPING_LINE
                || !$this->carries($kept, $line)
            ) {
                continue;
            }
            $net = (float)($line['net_amount'] ?? 0);
            $tax = (float)($line['tax_amount'] ?? 0);
            $rate = (float)($line['tax_rate'] ?? 0);
            $discount = abs((float)($line['discount_amount'] ?? 0));
            $off = min(abs($tax - $net * $rate), $discount > 0 ? abs($tax - ($net + $discount) * $rate) : INF);
            $quantity = max(1.0, (float)($line['quantity'] ?? 1));
            $tolerance = max(self::TOLERANCE, self::UNIT_ROUNDING_TOLERANCE * $quantity)
                + self::RATE_PRECISION_TOLERANCE * abs($net) + 1e-9;
            if ($off <= $tolerance) {
                continue;
            }
            $this->logRepository->addErrorLog('ShopMatchLineTaxRefused', sprintf(
                'The %s request\'s line %s declares rate %.6F, but Magento charged tax %.2F on net %.2F '
                . '(off by %.2F, tolerance %.2F).',
                $requestType,
                is_scalar($line['order_item_id'] ?? null) ? (string)$line['order_item_id'] : 'unknown',
                $rate,
                $tax,
                $net,
                $off,
                $tolerance
            ));
            throw new ShopMatchRefusedException($this->refusalMessage($requestType));
        }
    }

    /**
     * The result's lines against the order's own totals plus its tenders: a
     * tender is a payment, so it adds to gross and net, never to tax.
     *
     * @param array $result
     * @param array $context
     * @return void
     * @throws ShopMatchRefusedException
     */
    private function checkWholeOrder(array $result, array $context): void
    {
        $requestType = (string)($context['request_type'] ?? '');
        if (!in_array($requestType, self::WHOLE_ORDER_REQUESTS, true)) {
            return;
        }
        $order = $requestType === Hook::REQUEST_ORDER_INTENT
            ? ($context['intent_order'] ?? null)
            : ($context['order'] ?? null);
        $lines = array_filter($this->lines($result, $requestType), 'is_array');
        if (!$order instanceof Order || $lines === []) {
            return;
        }
        $tendered = $this->composeOrder->getTenderTotal($order);
        $tax = round((float)$order->getTaxAmount(), 2);
        $gross = round((float)$order->getGrandTotal() + $tendered, 2);
        $expected = ['gross' => $gross, 'net' => round($gross - $tax, 2), 'tax' => $tax];
        $sums = ['gross' => 0.0, 'net' => 0.0, 'tax' => 0.0];
        foreach ($lines as $line) {
            foreach (array_keys($sums) as $figure) {
                $sums[$figure] += (float)($line[$figure . '_amount'] ?? 0);
            }
        }
        // Each line is rounded on its own, by up to half a cent, and the order once more.
        $tolerance = max(self::TOLERANCE, (count($lines) + 1) * 0.005) + 1e-9;
        $drifted = [];
        foreach ($expected as $figure => $value) {
            $sum = round($sums[$figure], 2);
            if (abs($sum - $value) > $tolerance) {
                $drifted[] = sprintf('%s order %.2F vs order lines %.2F', $figure, $value, $sum);
            }
        }
        if ($drifted === []) {
            return;
        }
        $log = sprintf(
            'The %s request\'s order lines do not add up to the order totals plus tenders of %.2F: %s. '
            . 'Unexplained gap %.2F: a payment taken outside the order lines (a gift card, store credit) '
            . 'can be declared with a plugin on %s.',
            $requestType,
            $tendered,
            implode('; ', $drifted),
            round($sums['gross'] - $gross, 2),
            \Two\Gateway\Api\OrderTendersInterface::class
        );
        if ($requestType === Hook::REQUEST_ORDER_INTENT) {
            $this->logRepository->addErrorLog('ShopMatchWholeOrderDrift', $log . ' Logged only: the order create checks it again.');
            return;
        }
        $this->logRepository->addErrorLog('ShopMatchWholeOrderRefused', $log);
        throw new ShopMatchRefusedException($this->refusalMessage($requestType));
    }

    /**
     * @param string $requestType
     * @return \Magento\Framework\Phrase
     */
    private function refusalMessage(string $requestType)
    {
        return $requestType === Hook::REQUEST_ORDER_UPDATE
            ? __('The order lines do not add up to the order total.')
            : __('This order could not be placed. Please contact the merchant.');
    }

    /**
     * @param array $payload
     * @param string $requestType
     * @return array
     */
    private function lines(array $payload, string $requestType): array
    {
        $lines = $requestType === Hook::REQUEST_CAPTURE
            ? ($payload['partial']['line_items'] ?? [])
            : ($payload['line_items'] ?? []);

        return is_array($lines) ? $lines : [];
    }

    /**
     * @param array $lines
     * @return array|null
     */
    private function shippingLine(array $lines): ?array
    {
        foreach ($lines as $line) {
            if (is_array($line) && ($line['order_item_id'] ?? null) === self::SHIPPING_LINE) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Whether $lines still carry $line exactly, whatever the key order.
     *
     * @param array $lines
     * @param array $line
     * @return bool
     */
    private function carries(array $lines, array $line): bool
    {
        $line = $this->canonical($line);
        foreach ($lines as $candidate) {
            if (is_array($candidate) && $this->canonical($candidate) === $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $value
     * @return array
     */
    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
