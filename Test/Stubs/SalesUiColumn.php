<?php
declare(strict_types=1);

namespace Magento\Sales\Ui\Component\Listing\Column;

/**
 * UI listing column with DataObject semantics, so a plugin that rewrites
 * `config` before prepare() runs against a real data bag rather than the
 * catch-all's method-less stub.
 */
class Price extends \Magento\Framework\DataObject
{
    public function prepare(): void
    {
    }
}
