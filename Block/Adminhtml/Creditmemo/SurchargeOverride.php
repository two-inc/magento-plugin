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

/**
 * Renders the "Refund Two Surcharge" override input on the new-creditmemo
 * form. Pre-fills with the proportional default the collector would compute,
 * so the merchant only has to type when overriding.
 */
class SurchargeOverride extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var FormatInterface
     */
    private $localeFormat;

    public function __construct(
        Context $context,
        Registry $registry,
        FormatInterface $localeFormat,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->localeFormat = $localeFormat;
    }

    /**
     * Replace the static surcharge row registered by Block\Sales\Total\Surcharge
     * with an editable input row that points at this block's template.
     *
     * Mirrors how Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Adjustments
     * collapses the standard shipping/adjustment rows into its own editable
     * block. Runs from the parent totals block's _beforeToHtml after our own
     * Surcharge::initTotals has registered the read-only entry, so the order
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
        $parent->removeTotal('two_surcharge');
        // Pass the alias (not the full name-in-layout) so the parent
        // totals.phtml's $block->getChildHtml($code) lookup resolves.
        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_surcharge',
                'block_name' => 'two_surcharge_override',
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
     * Total surcharge available to refund — order amount minus prior refunds.
     */
    public function getMaxRefundable(): float
    {
        $order = $this->getOrder();
        if (!$order) {
            return 0.0;
        }
        return max(
            0.0,
            (float)$order->getTwoSurchargeAmount() - (float)$order->getTwoSurchargeRefunded()
        );
    }

    /**
     * Default value for the input. Prefers whatever collectTotals just
     * produced on the creditmemo (which honours any merchant override
     * stamped via the Plugin\Model\Sales\CreditmemoSurchargeOverride
     * beforeCollectTotals plugin), falling back to the proportional
     * default when the field has not been collected yet.
     */
    public function getDefaultRefund(): float
    {
        $cm = $this->getCreditmemo();
        $order = $this->getOrder();
        if (!$cm || !$order) {
            return 0.0;
        }
        $current = (float)$cm->getTwoSurchargeAmount();
        if ($current > 0) {
            return min($current, $this->getMaxRefundable());
        }
        $orderSubtotal = (float)$order->getSubtotal();
        $cmSubtotal = (float)$cm->getSubtotal();
        if ($orderSubtotal <= 0) {
            return 0.0;
        }
        $proportion = $cmSubtotal / $orderSubtotal;
        $value = round((float)$order->getTwoSurchargeAmount() * $proportion, 2);
        return min($value, $this->getMaxRefundable());
    }

    public function shouldDisplay(): bool
    {
        $order = $this->getOrder();
        return $order && (float)$order->getTwoSurchargeAmount() > 0;
    }

    /**
     * Label for the row — mirrors the order's surcharge description so the
     * editable line on the creditmemo create form reads the same as the
     * static line shown elsewhere (e.g. "Zakelijk op Rekening - 30 dagen"
     * prefixed with "Refund").
     */
    public function getLabel(): string
    {
        $order = $this->getOrder();
        $description = $order ? (string)$order->getTwoSurchargeDescription() : '';
        if ($description !== '') {
            return (string)__('Refund %1', $description);
        }
        return (string)__('Refund Two Surcharge');
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
     * en_*). No grouping separator is emitted by the caller — refund
     * surcharges are small by construction and the override parser
     * (Plugin\Model\Sales\CreditmemoSurchargeOverride) intentionally rejects
     * thousands separators.
     */
    protected function localeDecimalSymbol(): string
    {
        $format = $this->localeFormat->getPriceFormat();
        return (string)($format['decimalSymbol'] ?? '.');
    }
}
