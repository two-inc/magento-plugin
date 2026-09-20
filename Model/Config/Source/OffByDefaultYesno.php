<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Yes/No with No FIRST, for an opt-in whose default must survive a brand that
 * ships no declared default (TWO-25800).
 *
 * Magento renders a select with no stored value as its first option, and
 * `etc/config.xml` can only declare a default under this module's own payment
 * code — an overlay brand's fields resolve `payment/<its code>/...`, for which
 * this package declares nothing. With core's `Yesno`, whose first option is
 * Yes, an overlay's default-scope form therefore DISPLAYS Yes for a feature
 * that is off, and saving that section posts 1 and switches on a buyer-facing
 * control nobody chose.
 *
 * Ordering No first makes the form agree with the runtime on every brand,
 * including brands this package has never heard of: the read path already
 * treats an absent row as off, and now the screen says so too. That is the
 * whole reason this exists rather than core's source, and it is why the option
 * order must not be "tidied" back.
 *
 * The stored values are core's own 1 and 0, so nothing downstream changes.
 */
class OffByDefaultYesno implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 0, 'label' => __('No')],
            ['value' => 1, 'label' => __('Yes')],
        ];
    }
}
