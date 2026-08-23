<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee\Provider;

use Magento\Framework\App\ObjectManager;

/**
 * Isolates the one part of AmastyExtraFee that has to reach for Amasty's
 * internal resource model rather than a documented API.
 *
 * amasty/module-extra-fee is not a build dependency of this repo (it is
 * only ever installed on a merchant's live Magento), so the collection
 * class cannot be type-hinted or use'd - PHP (and Magento's DI compiler,
 * which reflects on every constructor for every merchant regardless of
 * whether they run Amasty) needs the class to exist the moment a typed
 * reference to it is parsed. class_exists() plus ObjectManager::get() is
 * the same pattern Amasty's own Quote\Fee total collector uses for its
 * optional constructor arguments.
 */
class AmastyExtraFeeQuoteReader
{
    private const FEE_QUOTE_COLLECTION_FACTORY =
        'Amasty\Extrafee\Model\ResourceModel\ExtrafeeQuote\CollectionFactory';

    /**
     * @return array{net_amount: float, tax_amount: float}|null
     */
    public function getFeeByQuoteId(int $quoteId): ?array
    {
        if (!class_exists(self::FEE_QUOTE_COLLECTION_FACTORY)) {
            return null;
        }

        $factory = ObjectManager::getInstance()->get(self::FEE_QUOTE_COLLECTION_FACTORY);
        $row = $factory->create()->getFeeByQuoteId($quoteId);
        if (!$row) {
            return null;
        }

        return [
            'net_amount' => (float)($row['fee_amount'] ?? 0),
            'tax_amount' => (float)($row['tax_amount'] ?? 0),
        ];
    }
}
