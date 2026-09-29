<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\OrderPostprocessingFixture\Plugin;

use RuntimeException;
use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Api\OrderPostprocessingTotalsInterface;

/**
 * CI fixture subscriber (TWO-26092): records every call and, once armed,
 * edits the payload the way a merchant's subscriber might. Also the working
 * example the README points at for the re-split.
 */
class Subscriber
{
    public const MODE_RESPLIT = 'resplit';
    public const MODE_RESPLIT_WITHOUT_TOTALS = 'resplit_without_totals';
    public const MODE_GROSS_CHANGE = 'gross_change';
    public const MODE_LINE_OFF = 'line_off';
    public const MODE_SUBTOTALS_STALE = 'subtotals_stale';
    public const MODE_THROW = 'throw';
    public const MODE_RETURN_NON_ARRAY = 'return_non_array';
    public const MODE_BODY_ON_BODYLESS = 'body_on_bodyless';

    /** @var string|null Null leaves the payload untouched. */
    public static $mode = null;

    /** @var array<int, array{request_type: string, trigger: string, endpoint: string, context: array}> */
    public static $calls = [];

    /**
     * @var OrderPostprocessingTotalsInterface
     */
    private $totals;

    public function __construct(OrderPostprocessingTotalsInterface $totals)
    {
        $this->totals = $totals;
    }

    /**
     * @param OrderPostprocessingInterface $subject
     * @param array $result
     * @param array $payload
     * @param array $context
     * @return array
     */
    public function afterProcess(
        OrderPostprocessingInterface $subject,
        array $result,
        array $payload,
        array $context
    ) {
        self::$calls[] = [
            'request_type' => $context['request_type'],
            'trigger' => $context['trigger'],
            'endpoint' => $context['endpoint'],
            'context' => $context,
        ];

        switch (self::$mode) {
            case self::MODE_RESPLIT:
                return $this->totals->recompute($this->resplitShipping($result, $context));
            case self::MODE_RESPLIT_WITHOUT_TOTALS:
                return $this->resplitShipping($result, $context);
            case self::MODE_GROSS_CHANGE:
                return $this->totals->recompute($this->addToShipping($result, 1.00));
            case self::MODE_LINE_OFF:
                return $this->editLines($result, static function (array $line): array {
                    $line['tax_amount'] = number_format((float)$line['tax_amount'] + 1.00, 2, '.', '');
                    return $line;
                });
            case self::MODE_SUBTOTALS_STALE:
                return $this->resplitShipping($result, $context, true);
            case self::MODE_THROW:
                throw new RuntimeException('fixture subscriber failed');
            case self::MODE_RETURN_NON_ARRAY:
                return 'not a payload';
            case self::MODE_BODY_ON_BODYLESS:
                return $result === [] ? ['note' => 'added by a subscriber'] : $result;
            default:
                return $result;
        }
    }

    /**
     * Treat an untaxed shipping charge as VAT-inclusive at the shop's
     * configured shipping rate: net = round(gross / (1 + rate), 2).
     *
     * @param array $payload
     * @param array $context
     * @param bool $totalsOnly leave the subtotals as they were, to break G4
     * @return array
     */
    private function resplitShipping(array $payload, array $context, bool $totalsOnly = false): array
    {
        $rate = $context['shipping_tax_rate'] ?? null;
        if (!$rate) {
            return $payload;
        }
        $subtotals = $payload['tax_subtotals'] ?? null;
        $payload = $this->editLines($payload, static function (array $line) use ($rate): array {
            if (($line['type'] ?? '') !== 'SHIPPING_FEE' || (float)$line['tax_amount'] != 0.0) {
                return $line;
            }
            $gross = (float)$line['gross_amount'];
            $net = round($gross / (1 + $rate), 2);
            $line['net_amount'] = number_format($net, 2, '.', '');
            $line['tax_amount'] = number_format($gross - $net, 2, '.', '');
            $line['unit_price'] = $line['net_amount'];
            $line['tax_rate'] = number_format($rate, 6, '.', '');
            $line['tax_class_name'] = 'VAT ' . number_format($rate * 100, 2) . '%';
            return $line;
        });
        if ($totalsOnly) {
            $payload = $this->totals->recompute($payload);
            $payload['tax_subtotals'] = $subtotals;
        }

        return $payload;
    }

    /**
     * @param array $payload
     * @param float $amount
     * @return array
     */
    private function addToShipping(array $payload, float $amount): array
    {
        return $this->editLines($payload, static function (array $line) use ($amount): array {
            if (($line['type'] ?? '') === 'SHIPPING_FEE') {
                foreach (['gross_amount', 'net_amount', 'unit_price'] as $field) {
                    $line[$field] = number_format((float)$line[$field] + $amount, 2, '.', '');
                }
            }
            return $line;
        });
    }

    /**
     * Apply $edit to every line, at the top level or in a capture's `partial`.
     *
     * @param array $payload
     * @param callable $edit
     * @return array
     */
    private function editLines(array $payload, callable $edit): array
    {
        if (isset($payload['line_items'])) {
            $payload['line_items'] = array_map($edit, $payload['line_items']);
        }
        if (isset($payload['partial']['line_items'])) {
            $payload['partial']['line_items'] = array_map($edit, $payload['partial']['line_items']);
        }

        return $payload;
    }
}
