<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee\Provider;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as OrderModel;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;

/**
 * Itemizes Amasty's "Extra Fee" module (amasty/module-extra-fee) total.
 *
 * Amasty's quote-side total collector (Amasty\Extrafee\Model\Quote\Fee)
 * aggregates every selected fee option into ONE combined amount/tax
 * before it ever reaches an order, so this always emits at most one line
 * — there is no order-level per-option breakdown to itemize even if we
 * wanted one.
 *
 * Amasty\Extrafee\Plugin\Order\OrderRepository populates these
 * OrderInterface extension attributes from its own quote-fee table, but
 * ONLY on afterGet/afterGetList of the core order repository. The $entity
 * ComposeOrder hands us is $payment->getOrder() — the in-memory object
 * from the current placement transaction, never loaded through
 * OrderRepositoryInterface — so Amasty's plugin has not run on it and its
 * extension attributes are never populated. Reloading by entity ID here
 * forces that plugin to run; the order row is already persisted by the
 * time ComposeOrder executes (see its docblock), so the reload finds it.
 * No direct dependency on Amasty's module either way — this only ever
 * touches the OrderInterface extension attributes, guarded with
 * method_exists() since they only exist when amasty/module-extra-fee is
 * actually installed.
 */
class AmastyExtraFee implements FeeLineProviderInterface
{
    private const NET_AMOUNT_GETTER = 'getAmextrafeeFeeAmount';
    private const TAX_AMOUNT_GETTER = 'getAmextrafeeTaxAmount';

    private OrderRepositoryInterface $orderRepository;

    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array
    {
        if (!$entity instanceof OrderModel || !$entity->getEntityId()) {
            return [];
        }

        $extensionAttributes = $this->reloadedExtensionAttributes((int)$entity->getEntityId());
        if ($extensionAttributes === null || !method_exists($extensionAttributes, self::NET_AMOUNT_GETTER)) {
            return [];
        }

        $netAmount = (float)$extensionAttributes->{self::NET_AMOUNT_GETTER}();
        if ($netAmount <= 0.0) {
            return [];
        }

        $taxAmount = method_exists($extensionAttributes, self::TAX_AMOUNT_GETTER)
            ? (float)$extensionAttributes->{self::TAX_AMOUNT_GETTER}()
            : 0.0;
        $grossAmount = $netAmount + $taxAmount;
        $taxRate = $taxAmount > 0.0 ? $taxAmount / $netAmount : 0.0;

        return [[
            'order_item_id' => 'amasty_extrafee',
            'name' => (string)__('Amasty Fee'),
            'description' => (string)__('Amasty Fee'),
            'type' => 'OTHER',
            'image_url' => '',
            'product_page_url' => '',
            'gross_amount' => $this->roundAmt($grossAmount),
            'net_amount' => $this->roundAmt($netAmount),
            'tax_amount' => $this->roundAmt($taxAmount),
            'discount_amount' => '0.00',
            'tax_rate' => $this->roundAmt($taxRate, 6),
            'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
            'unit_price' => $this->roundAmt($netAmount, 6),
            'quantity' => 1,
            'quantity_unit' => 'sc',
        ]];
    }

    private function reloadedExtensionAttributes(int $orderId): ?OrderExtensionInterface
    {
        try {
            return $this->orderRepository->get($orderId)->getExtensionAttributes();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function roundAmt(float $amt, int $dp = 2): string
    {
        return number_format($amt, $dp, '.', '');
    }
}
