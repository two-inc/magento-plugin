<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Model\Sales;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order\Creditmemo;
use Two\Gateway\Service\Order\OtherChargesResolver;

/**
 * Reads the merchant-typed fee refund from the creditmemo form post and stamps
 * it on the creditmemo before collectTotals runs.
 *
 * Two fields, one parser: `creditmemo[two_surcharge_amount]` for our own
 * surcharge, `creditmemo[two_other_charges_amount]` for a third-party
 * extension's unitemized fee. The admin Creditmemo Save controller builds the
 * creditmemo via CreditmemoFactory which calls collectTotals; collectTotals
 * invokes the matching Two\Gateway\Model\Total\Creditmemo collector, which
 * picks up the stamped value.
 *
 * Without this plugin the form inputs would be silently discarded (Magento
 * doesn't auto-bind the creditmemo[*] payload to non-core fields).
 */
class CreditmemoFeeOverride
{
    private const SURCHARGE = 'two_surcharge_amount';

    private const OTHER_CHARGES = 'two_other_charges_amount';

    /**
     * Cap fuzz, so a pre-filled default that round-tripped through a 2dp
     * display doesn't trip a cap it actually sits on. The collector clamps to
     * the real cap regardless, so the tolerance cannot over-refund.
     */
    private const CAP_TOLERANCE = 0.01;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    /**
     * @var OtherChargesResolver
     */
    private $otherChargesResolver;

    public function __construct(
        RequestInterface $request,
        FormatInterface $localeFormat,
        OtherChargesResolver $otherChargesResolver
    ) {
        $this->request = $request;
        $this->localeFormat = $localeFormat;
        $this->otherChargesResolver = $otherChargesResolver;
    }

    /**
     * @param Creditmemo $subject
     * @return null
     * @throws LocalizedException
     */
    public function beforeCollectTotals(Creditmemo $subject)
    {
        // Scope to the admin creditmemo create/save actions so an arbitrary
        // controller that happens to construct a Creditmemo via DI in the
        // same request can't have a `creditmemo[*]` query string injected
        // into its totals collection.
        $controller = $this->request->getControllerName();
        $action = $this->request->getActionName();
        $isCreditmemoSave = $controller === 'order_creditmemo'
            && in_array($action, ['save', 'updateQty'], true);
        if (!$isCreditmemoSave) {
            return null;
        }

        $data = $this->request->getParam('creditmemo');
        if (!is_array($data)) {
            return null;
        }

        foreach ([self::SURCHARGE, self::OTHER_CHARGES] as $field) {
            $this->stamp($subject, $data, $field);
        }

        return null;
    }

    /**
     * @param Creditmemo $subject
     * @param array $data
     * @param string $field
     * @throws LocalizedException
     */
    private function stamp(Creditmemo $subject, array $data, string $field): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }

        $raw = $data[$field];
        if ($raw === '' || $raw === null) {
            // A cleared field means "refund none of this fee" — an explicit 0,
            // not "fall back to the proportional default", so the merchant's
            // intent survives the recalc round-trip instead of snapping back.
            $subject->setData($field, 0.0);
            return;
        }

        $value = $this->parse($raw, $field);

        // Validate against what the order has left, so the merchant gets an
        // explicit error rather than a silent cap.
        $maxRefundable = $this->maxRefundable($subject, $field);
        if ($maxRefundable !== null && $value - $maxRefundable > self::CAP_TOLERANCE) {
            throw new LocalizedException(
                $this->exceedsMessage($field, $value, max(0.0, $maxRefundable))
            );
        }

        $subject->setData($field, $value);
    }

    /**
     * @param mixed $raw
     * @param string $field
     * @return float
     * @throws LocalizedException
     */
    private function parse($raw, string $field): float
    {
        // Strip leading/trailing horizontal whitespace including U+00A0
        // (NBSP) — currency-paste from nl_NL displays like "€ 1,50"
        // resolves to "\xc2\xa01,50" once the symbol is removed.
        // Magento\Framework\Locale\Format::getNumber strips regular
        // spaces but NOT NBSP, so it would silently return 0.0 — the
        // exact failure mode the regex pre-check is meant to catch.
        // Trim once here, then both validation and parsing see the
        // same canonical string.
        $trimmed = is_scalar($raw)
            ? (string)preg_replace('/^\h+|\h+$/u', '', (string)$raw)
            : '';

        // Accept both en_* ("1.50") and nl_* ("1,50") decimal separators
        // — the admin's locale governs what they type. Plain is_numeric
        // rejects "1,50" and breaks nl_NL admins. The regex pre-check is
        // necessary because FormatInterface::getNumber() returns 0.0
        // silently for unparseable strings (e.g. "abc"), which would
        // otherwise be indistinguishable from a legitimate "0" entry.
        // [-+] preserves the leading-sign tolerance the previous
        // is_numeric had. No thousands-separator support — refunded fees
        // are small by construction.
        if (!is_scalar($raw)
            || !preg_match('/^[-+]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/', $trimmed)
        ) {
            throw new LocalizedException($this->invalidMessage($field));
        }

        $value = (float)$this->localeFormat->getNumber($trimmed);
        if ($value < 0) {
            throw new LocalizedException($this->negativeMessage($field));
        }

        return $value;
    }

    /**
     * What the order still has left of this fee, or null when there is no cap
     * to check the typed value against.
     *
     * @param Creditmemo $creditmemo
     * @param string $field
     * @return float|null
     */
    private function maxRefundable(Creditmemo $creditmemo, string $field): ?float
    {
        $order = $creditmemo->getOrder();
        if (!$order) {
            return null;
        }

        if ($field === self::SURCHARGE) {
            $charged = (float)$order->getTwoSurchargeAmount();

            return $charged > 0
                ? $charged - (float)$order->getTwoSurchargeRefunded()
                : null;
        }

        // No order column records a third-party fee: the resolver derives it
        // from what the grand total exceeds, and earlier memos are the only
        // record of what has already been taken.
        $residual = $this->otherChargesResolver->forOrder($order);
        $charged = $residual ? (float)($residual['net_amount'] ?? 0) : 0.0;
        if ($charged <= 0) {
            return null;
        }

        [$refunded] = $this->otherChargesResolver->priorRefunds($order, $creditmemo);

        return $charged - $refunded;
    }

    private function invalidMessage(string $field): Phrase
    {
        return $field === self::SURCHARGE
            ? __('Surcharge refund must be a valid amount (e.g. 1.50 or 1,50).')
            : __('Other charges refund must be a valid amount (e.g. 1.50 or 1,50).');
    }

    private function negativeMessage(string $field): Phrase
    {
        return $field === self::SURCHARGE
            ? __('Surcharge refund cannot be negative.')
            : __('Other charges refund cannot be negative.');
    }

    private function exceedsMessage(string $field, float $value, float $max): Phrase
    {
        return $field === self::SURCHARGE
            ? __('Surcharge refund (%1) exceeds the remaining refundable surcharge (%2).', $value, $max)
            : __(
                'Other charges refund (%1) exceeds the remaining refundable other charges (%2).',
                $value,
                $max
            );
    }
}
