<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Two\Gateway\Api\OrderTendersInterface;

/**
 * The tenders Adobe Commerce records on an entity (TWO-26309): the gift card
 * account, store credit (customer balance) and reward points amounts, each
 * already taken off the grand total. Read as plain data, so Magento Open
 * Source, which has none of those modules, reads none.
 */
class OrderTenders implements OrderTendersInterface
{
    /**
     * The amount fields by tender, in the entity's own currency. An order,
     * invoice and credit memo record each tender's share under the same name;
     * a quote records what it uses under its own.
     */
    private const FIELDS = [
        'gift_card_account' => [
            'type' => self::TYPE_GIFT_CARD,
            'label' => 'Gift card',
            'entity' => 'gift_cards_amount',
            'quote' => 'gift_cards_amount_used',
        ],
        'customer_balance' => [
            'type' => self::TYPE_STORE_CREDIT,
            'label' => 'Store credit',
            'entity' => 'customer_balance_amount',
            'quote' => 'customer_balance_amount_used',
        ],
        'reward' => [
            'type' => self::TYPE_OTHER,
            'label' => 'Reward points',
            'entity' => 'reward_currency_amount',
            'quote' => 'reward_currency_amount',
        ],
    ];

    /**
     * @inheritDoc
     */
    public function collect($entity): array
    {
        if (!$entity instanceof DataObject) {
            return [];
        }
        $tenders = [];
        foreach (self::FIELDS as $source => $field) {
            $amount = $entity->getData($entity instanceof Quote ? $field['quote'] : $field['entity']);
            if (is_numeric($amount) && (float)$amount != 0.0) {
                // Labelled by kind only: a gift card's own name is often its code.
                $tenders[] = [
                    'type' => $field['type'],
                    'label' => $field['label'],
                    'amount' => (float)$amount,
                    'source' => $source,
                ];
            }
        }

        return $tenders;
    }
}
