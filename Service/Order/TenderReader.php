<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderTendersInterface;
use WeakMap;

/**
 * The tenders of an entity as the payload and the hook's `order_tenders` use
 * them (TWO-26309): OrderTendersInterface's list, merchant plugins included,
 * checked and normalised to `{type, label, amount, source}` with amount a
 * positive 2dp string. Read once per entity per request.
 */
class TenderReader
{
    private const TYPES = [
        OrderTendersInterface::TYPE_GIFT_CARD,
        OrderTendersInterface::TYPE_STORE_CREDIT,
        OrderTendersInterface::TYPE_OTHER,
    ];

    /** Longest type, source or amount a dropped entry's log line carries. */
    private const MAX_LOGGED = 120;

    /**
     * @var OrderTendersInterface
     */
    private $tenders;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var WeakMap<object, array>
     */
    private $read;

    public function __construct(OrderTendersInterface $tenders, LogRepository $logRepository)
    {
        $this->tenders = $tenders;
        $this->logRepository = $logRepository;
        $this->read = new WeakMap();
    }

    /**
     * @param object|null $entity an order, quote, invoice or credit memo
     * @return array<int, array{type: string, label: string, amount: string, source: string}>
     */
    public function read($entity): array
    {
        if (!is_object($entity)) {
            return [];
        }
        if (!isset($this->read[$entity])) {
            $this->read[$entity] = $this->normalise($this->tenders->collect($entity));
        }

        return $this->read[$entity];
    }

    /**
     * @param array $tenders a list read()
     * @return float their sum, 2dp
     */
    public static function total(array $tenders): float
    {
        $total = 0.0;
        foreach ($tenders as $tender) {
            $total += is_array($tender) && is_numeric($tender['amount'] ?? null) ? (float)$tender['amount'] : 0.0;
        }

        return round($total, 2);
    }

    /**
     * @param mixed $declared
     * @return array
     */
    private function normalise($declared): array
    {
        $valid = [];
        foreach (is_array($declared) ? $declared : [] as $tender) {
            $raw = is_array($tender) ? ($tender['amount'] ?? null) : null;
            $amount = is_numeric($raw) ? round((float)$raw, 2) : null;
            if ($amount === 0.0) {
                continue;
            }
            if ($amount === null || $amount < 0) {
                // Never the label, which can hold a card code.
                $this->logRepository->addErrorLog('OrderTenderDropped', [
                    'type' => $this->logged($tender, 'type'),
                    'source' => $this->logged($tender, 'source'),
                    'amount' => $this->logged($tender, 'amount'),
                ]);
                continue;
            }
            $type = $tender['type'] ?? null;
            $valid[] = [
                'type' => is_string($type) && in_array($type, self::TYPES, true) ? $type : OrderTendersInterface::TYPE_OTHER,
                'label' => is_scalar($tender['label'] ?? null) ? (string)$tender['label'] : '',
                'amount' => number_format($amount, 2, '.', ''),
                'source' => is_scalar($tender['source'] ?? null) ? (string)$tender['source'] : 'plugin',
            ];
        }

        return $valid;
    }

    /**
     * @param mixed $tender
     * @param string $key
     * @return string
     */
    private function logged($tender, string $key): string
    {
        $value = is_array($tender) && is_scalar($tender[$key] ?? null) ? (string)$tender[$key] : 'none';

        return mb_strcut($value, 0, self::MAX_LOGGED, 'UTF-8');
    }
}
