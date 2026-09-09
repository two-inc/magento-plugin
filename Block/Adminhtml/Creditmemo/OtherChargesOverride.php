<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\Creditmemo;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\DataObject;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Registry;
use Two\Gateway\Service\Order\OtherChargesResolver;

/**
 * Renders the "Refund other charges" override input on the new-creditmemo
 * form. Pre-fills with the proportional default the collector would compute,
 * so the merchant only has to type when overriding.
 *
 * The row names no source: nothing on this path learns which extension the
 * charge came from.
 */
class OtherChargesOverride extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    /**
     * @var OtherChargesResolver
     */
    private $otherChargesResolver;

    /**
     * Resolving the residual walks the order's lines, and every accessor here
     * needs it.
     *
     * @var array|null|false false until resolved.
     */
    private $residual = false;

    public function __construct(
        Context $context,
        Registry $registry,
        FormatInterface $localeFormat,
        OtherChargesResolver $otherChargesResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->localeFormat = $localeFormat;
        $this->otherChargesResolver = $otherChargesResolver;
    }

    /**
     * Replace the static charge row registered by Block\Sales\Total\OtherCharges
     * with an editable input row that points at this block's template.
     *
     * Mirrors how Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Adjustments
     * collapses the standard shipping/adjustment rows into its own editable
     * block. Runs from the parent totals block's _beforeToHtml after
     * OtherCharges::initTotals has registered the read-only entry, so the order
     * of operations is: static added → we remove it → we add the editable
     * placeholder pointing at this template.
     */
    public function initTotals(): self
    {
        if (!$this->shouldDisplay()) {
            return $this;
        }
        $parent = $this->getParentBlock();
        if (!$parent) {
            return $this;
        }
        // Both the net/gross pair the "Both" display mode emits and the single
        // row the other modes emit.
        $parent->removeTotal('two_other_charges');
        $parent->removeTotal('two_other_charges_excl');
        $parent->removeTotal('two_other_charges_incl');
        // Pass the alias (not the full name-in-layout) so the parent
        // totals.phtml's $block->getChildHtml($code) lookup resolves.
        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_other_charges',
                'block_name' => 'two_other_charges_override',
                'strong'     => false,
            ]),
            'tax'
        );

        return $this;
    }

    public function getCreditmemo()
    {
        return $this->registry->registry('current_creditmemo');
    }

    public function getOrder()
    {
        $cm = $this->getCreditmemo();
        return $cm ? $cm->getOrder() : null;
    }

    /**
     * Total charge available to refund — the order's derived residual minus
     * what earlier credit memos took. No order column records it.
     */
    public function getMaxRefundable(): float
    {
        $order = $this->getOrder();
        if (!$order) {
            return 0.0;
        }

        $charged = $this->chargedNet();
        if ($charged <= 0) {
            return 0.0;
        }

        return max(0.0, $charged - $this->priorRefunded());
    }

    /**
     * What earlier credit memos took of the charge.
     */
    protected function priorRefunded(): float
    {
        $order = $this->getOrder();
        if (!$order) {
            return 0.0;
        }
        [$refunded] = $this->otherChargesResolver->priorRefunds($order, $this->getCreditmemo());

        return (float)$refunded;
    }

    /**
     * Default value for the input. Prefers whatever collectTotals just produced
     * on the creditmemo (which honours any merchant override stamped via the
     * Plugin\Model\Sales\CreditmemoFeeOverride beforeCollectTotals plugin),
     * falling back to the proportional default when the field has not been
     * collected yet.
     */
    public function getDefaultRefund(): float
    {
        $cm = $this->getCreditmemo();
        $order = $this->getOrder();
        if (!$cm || !$order) {
            return 0.0;
        }
        // Once collectTotals has run, the collector has resolved the refundable
        // charge — the proportional default OR the merchant's override,
        // including an explicit 0. Honour that value verbatim; testing `> 0`
        // would make an explicit 0 snap back to the full default, discarding
        // the merchant's "refund no charge".
        if ($cm->hasData('two_other_charges_amount')) {
            return min(max(0.0, (float)$cm->getTwoOtherChargesAmount()), $this->getMaxRefundable());
        }
        // Not yet collected (defensive) — fall back to the proportional default.
        $orderSubtotal = (float)$order->getSubtotal();
        if ($orderSubtotal <= 0) {
            return 0.0;
        }
        $proportion = (float)$cm->getSubtotal() / $orderSubtotal;

        return min(round($this->chargedNet() * $proportion, 2), $this->getMaxRefundable());
    }

    public function shouldDisplay(): bool
    {
        return $this->getOrder() && $this->chargedNet() > 0;
    }

    public function getLabel(): string
    {
        return (string)__('Refund other charges');
    }

    public function formatPrice($value): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return (string)$value;
        }

        return $order->formatPriceTxt((float)$value);
    }

    /**
     * The pre-filled input value, formatted for the admin locale: fixed 2dp
     * with the locale decimal separator (e.g. "2,50" for nl_NL, "2.50" for
     * en) and no grouping separator. Rendering the raw float instead would
     * surface as "2.5" after a recalc — losing the trailing zero and the
     * locale comma the merchant typed.
     */
    public function getFormattedDefaultRefund(): string
    {
        return number_format($this->getDefaultRefund(), 2, $this->localeDecimalSymbol(), '');
    }

    /**
     * Decimal separator for the current admin locale (',' for nl_NL, '.' for
     * en_*). No grouping separator is emitted by the caller — the override
     * parser (Plugin\Model\Sales\CreditmemoFeeOverride) rejects thousands
     * separators.
     */
    protected function localeDecimalSymbol(): string
    {
        $format = $this->localeFormat->getPriceFormat();

        return (string)($format['decimalSymbol'] ?? '.');
    }

    /**
     * The order's charge, net.
     */
    protected function chargedNet(): float
    {
        $order = $this->getOrder();
        if (!$order) {
            return 0.0;
        }
        if ($this->residual === false) {
            $this->residual = $this->otherChargesResolver->forOrder($order);
        }

        return $this->residual ? (float)($this->residual['net_amount'] ?? 0) : 0.0;
    }
}
