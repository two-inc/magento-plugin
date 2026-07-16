<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

/**
 * Merchant commercial settings, sourced from GET /v1/merchant/{id}.
 *
 * These values used to live in each brand's etc/brand.xml as
 * install-time constants. They are per-merchant commercial terms —
 * they vary between merchants of the same brand — so the merchant API
 * is their authoritative source, resolved server-side from the
 * merchant's pricing package / config with any partner or per-merchant
 * override already applied.
 *
 * Each accessor degrades to the same "nothing configured" outcome the
 * brand default used to express when the record cannot be resolved
 * (empty term set, no surcharge cap, no default term): an unconfigured
 * or invalid API key, or an API blip, must not harden into a wrong
 * commercial constraint.
 */
class SettingsProvider
{
    /**
     * @var RecordProvider
     */
    private $recordProvider;

    public function __construct(RecordProvider $recordProvider)
    {
        $this->recordProvider = $recordProvider;
    }

    /**
     * Offerable buyer payment terms (in net days) for the merchant.
     * The admin narrows the buyer-facing set from this; an empty array
     * means the set could not be resolved (the admin surfaces cannot
     * offer terms until a valid API key resolves).
     *
     * @return int[]
     */
    public function getAvailableTerms(?int $storeId = null): array
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return [];
        }
        $terms = $record['available_terms'] ?? null;
        if (!is_array($terms)) {
            return [];
        }
        $days = array_filter(
            array_map('intval', $terms),
            static fn(int $t): bool => $t > 0
        );
        $days = array_values(array_unique($days));
        sort($days);
        return $days;
    }

    /**
     * Maximum allowed value of a fixed-amount buyer surcharge the
     * merchant may configure, in a specific currency. Null means no
     * upper bound (any positive value is acceptable) — calling code
     * must interpret null as "no max" and skip the upper-bound check.
     *
     * The two surcharge_limit_* fields on the merchant record travel
     * together; a partial or malformed tuple is treated as "no cap".
     *
     * @return array{amount: float, currency: string}|null
     */
    public function getSurchargeLimit(?int $storeId = null): ?array
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return null;
        }
        $amount = $record['surcharge_limit_amount'] ?? null;
        $currency = $record['surcharge_limit_currency'] ?? null;
        if (!is_numeric($amount)
            || (float)$amount <= 0
            || !is_string($currency)
            || $currency === ''
        ) {
            return null;
        }
        return [
            'amount' => (float)$amount,
            'currency' => strtoupper($currency),
        ];
    }

    /**
     * The merchant's default invoice payment term (due_in_days), in net
     * days, or null when none is set or it cannot be resolved. Not
     * guaranteed to be a member of getAvailableTerms(); callers honour
     * it only when it is an offered term (see TWO-24859).
     */
    public function getDefaultTerm(?int $storeId = null): ?int
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return null;
        }
        $due = $record['due_in_days'] ?? null;
        if (!is_numeric($due) || (int)$due <= 0) {
            return null;
        }
        return (int)$due;
    }

    /**
     * Whether the merchant self-distributes their own invoices to the
     * buyer (invoice_distributed_by_merchant on the merchant record).
     * Absent, unresolvable, or malformed all degrade to false — the
     * plugin only ever generates/uploads an invoice PDF when the
     * merchant record explicitly says so. This is the sole gate: there
     * is deliberately no admin-configurable override (TWO-25106,
     * Option A).
     */
    public function isInvoiceDistributedByMerchant(?int $storeId = null): bool
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return false;
        }
        return ($record['invoice_distributed_by_merchant'] ?? false) === true;
    }
}
