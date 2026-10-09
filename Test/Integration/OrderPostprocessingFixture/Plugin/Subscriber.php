<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\OrderPostprocessingFixture\Plugin;

use RuntimeException;
use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Api\OrderPostprocessingShopMatchInterface;
use Two\Gateway\Api\OrderPostprocessingTotalsInterface;

/**
 * CI fixture subscriber (TWO-26092): records every call and, once armed,
 * edits the payload the way a merchant's subscriber might. Also the working
 * example the README points at for the re-split. Being registered at all
 * makes the plugin's default handler stand down (TWO-26276).
 */
class Subscriber
{
    public const MODE_RESPLIT = 'resplit';
    public const MODE_RESPLIT_WITHOUT_TOTALS = 'resplit_without_totals';
    public const MODE_GROSS_CHANGE = 'gross_change';
    public const MODE_THROW = 'throw';
    public const MODE_RETURN_NON_ARRAY = 'return_non_array';
    public const MODE_BODY_ON_BODYLESS = 'body_on_bodyless';
    public const MODE_NOT_ENCODABLE = 'not_encodable';
    public const MODE_ADD_LINE = 'add_line';
    public const MODE_LINES_DO_NOT_ADD_UP = 'lines_do_not_add_up';
    public const MODE_OPT_IN = 'opt_in';

    /** The rate MODE_ADD_LINE splits its line at. */
    public const ADDED_LINE_RATE = 0.21;

    /** @var string|null Null leaves the payload untouched. */
    public static $mode = null;

    /** @var array<int, array{request_type: string, trigger: string, endpoint: string, context: array}> */
    public static $calls = [];

    /**
     * @var OrderPostprocessingTotalsInterface
     */
    private $totals;

    /**
     * @var OrderPostprocessingShopMatchInterface
     */
    private $shopMatch;

    public function __construct(OrderPostprocessingTotalsInterface $totals, OrderPostprocessingShopMatchInterface $shopMatch)
    {
        $this->totals = $totals;
        $this->shopMatch = $shopMatch;
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
                return $this->totals->recompute($this->resplitShipping($result, $context), $result);
            case self::MODE_RESPLIT_WITHOUT_TOTALS:
                return $this->resplitShipping($result, $context);
            case self::MODE_GROSS_CHANGE:
                return $this->totals->recompute($this->addToShipping($result, 1.00), $result);
            case self::MODE_THROW:
                throw new RuntimeException('fixture subscriber failed');
            case self::MODE_RETURN_NON_ARRAY:
                return 'not a payload';
            case self::MODE_NOT_ENCODABLE:
                return $this->editLines($result, static function (array $line): array {
                    $line['net_amount'] = NAN;
                    return $line;
                });
            case self::MODE_BODY_ON_BODYLESS:
                return $result === [] ? ['note' => 'added by a subscriber'] : $result;
            case self::MODE_ADD_LINE:
                return $this->addLineForResidual($result);
            case self::MODE_LINES_DO_NOT_ADD_UP:
                // A product line whose tax no longer follows its rate.
                return $this->editLines($result, static function (array $line): array {
                    if (in_array($line['type'] ?? '', ['PHYSICAL', 'DIGITAL'], true)) {
                        $line['tax_amount'] = number_format((float)$line['tax_amount'] + 5.00, 2, '.', '');
                        $line['gross_amount'] = number_format((float)$line['gross_amount'] + 5.00, 2, '.', '');
                    }
                    return $line;
                });
            case self::MODE_OPT_IN:
                $this->shopMatch->check($result, $payload, $context);
                return $result;
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
     * @return array
     */
    private function resplitShipping(array $payload, array $context): array
    {
        $rate = $context['shipping_tax_rate'] ?? null;
        if (!$rate) {
            return $payload;
        }
        return $this->editLines($payload, static function (array $line) use ($rate): array {
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
    }

    /**
     * Itemise what the total carries beyond its lines, a cost the shop adds
     * outside any carrier, as one line taxed at ADDED_LINE_RATE. The gross is
     * the shop's; the tax total and subtotals follow the lines.
     *
     * @param array $payload
     * @return array
     */
    private function addLineForResidual(array $payload): array
    {
        $partial = isset($payload['partial']);
        $block = $partial ? $payload['partial'] : $payload;
        if (!isset($block['line_items'], $block['gross_amount'])) {
            return $payload;
        }
        $format = static fn (float $amount): string => number_format($amount, 2, '.', '');
        $gross = round((float)$block['gross_amount'] - array_sum(array_map('floatval', array_column($block['line_items'], 'gross_amount'))), 2);
        if ($gross <= 0) {
            return $payload;
        }
        $net = round($gross / (1 + self::ADDED_LINE_RATE), 2);
        $block['line_items'][] = [
            'order_item_id' => 'handling',
            'name' => 'Handling',
            'description' => 'Handling',
            'type' => 'OTHER',
            'gross_amount' => $format($gross),
            'net_amount' => $format($net),
            'tax_amount' => $format($gross - $net),
            'discount_amount' => '0.00',
            'tax_rate' => number_format(self::ADDED_LINE_RATE, 6, '.', ''),
            'tax_class_name' => 'VAT ' . number_format(self::ADDED_LINE_RATE * 100, 2) . '%',
            'unit_price' => $format($net),
            'quantity' => 1,
            'quantity_unit' => 'sc',
        ];
        $tax = array_sum(array_map('floatval', array_column($block['line_items'], 'tax_amount')));
        $block['tax_amount'] = $format($tax);
        $block['net_amount'] = $format((float)$block['gross_amount'] - $tax);
        if (isset($block['tax_subtotals'])) {
            $buckets = [];
            foreach ($block['line_items'] as $line) {
                $rate = (string)$line['tax_rate'];
                $buckets[$rate]['taxable_amount'] = ($buckets[$rate]['taxable_amount'] ?? 0.0) + (float)$line['net_amount'];
                $buckets[$rate]['tax_amount'] = ($buckets[$rate]['tax_amount'] ?? 0.0) + (float)$line['tax_amount'];
            }
            $block['tax_subtotals'] = [];
            foreach ($buckets as $rate => $bucket) {
                $block['tax_subtotals'][] = [
                    'taxable_amount' => $format($bucket['taxable_amount']),
                    'tax_amount' => $format($bucket['tax_amount']),
                    'tax_rate' => (string)$rate,
                ];
            }
        }

        if ($partial) {
            $payload['partial'] = $block;
            return $payload;
        }
        return $block;
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
