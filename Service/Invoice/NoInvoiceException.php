<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Invoice;

use RuntimeException;

/**
 * Thrown by UploadService::renderInvoicePdf() when the order has no
 * Magento invoice yet. Distinct from a generic render failure so the
 * caller can resolve to NOT_APPLICABLE (a legitimate outcome, e.g. a
 * zero-grand-total order that never gets a Magento invoice) instead of
 * FAILED.
 */
class NoInvoiceException extends RuntimeException
{
}
