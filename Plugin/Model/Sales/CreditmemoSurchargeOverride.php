<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Model\Sales;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Reads the merchant-typed surcharge override from the creditmemo form post
 * and stamps it on the creditmemo before collectTotals runs.
 *
 * The field is rendered by view/adminhtml/.../two_surcharge_input.phtml as
 * `creditmemo[two_surcharge_amount]`. The admin Creditmemo Save controller
 * builds the creditmemo via CreditmemoFactory which calls collectTotals;
 * collectTotals invokes Two\Gateway\Model\Total\Creditmemo\Surcharge which
 * picks up the stamped value.
 *
 * Without this plugin the form input would be silently discarded (Magento
 * doesn't auto-bind the creditmemo[*] payload to non-core fields).
 */
class CreditmemoSurchargeOverride
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    public function __construct(RequestInterface $request, FormatInterface $localeFormat)
    {
        $this->request = $request;
        $this->localeFormat = $localeFormat;
    }

    /**
     * @param Creditmemo $subject
     * @return null
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
        if (!is_array($data) || !array_key_exists('two_surcharge_amount', $data)) {
            return null;
        }

        $raw = $data['two_surcharge_amount'];
        if ($raw === '' || $raw === null) {
            // A cleared field means "refund no surcharge" — treat it as an
            // explicit 0 override (not "fall back to the proportional
            // default"), so the merchant's intent to zero the surcharge sticks
            // through the recalc round-trip instead of snapping back to full.
            $subject->setData('two_surcharge_amount', 0.0);
            return null;
        }

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
        // is_numeric had. No thousands-separator support — refund
        // surcharges are small by construction.
        if (!is_scalar($raw)
            || !preg_match('/^[-+]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/', $trimmed)
        ) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Surcharge refund must be a valid amount (e.g. 1.50 or 1,50).')
            );
        }
        $value = (float)$this->localeFormat->getNumber($trimmed);
        if ($value < 0) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Surcharge refund cannot be negative.')
            );
        }

        // Validate against the order's remaining refundable surcharge so the
        // merchant gets an explicit error instead of a silent cap when their
        // typed value exceeds what's available. Allow a 1-cent fuzz so
        // pre-filled defaults that round-tripped through display don't trip.
        $order = $subject->getOrder();
        if ($order && (float)$order->getTwoSurchargeAmount() > 0) {
            $maxRefundable = (float)$order->getTwoSurchargeAmount()
                - (float)$order->getTwoSurchargeRefunded();
            if ($value - $maxRefundable > 0.01) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __(
                        'Surcharge refund (%1) exceeds the remaining refundable surcharge (%2).',
                        $value,
                        max(0.0, $maxRefundable)
                    )
                );
            }
        }

        $subject->setData('two_surcharge_amount', $value);
        return null;
    }
}
