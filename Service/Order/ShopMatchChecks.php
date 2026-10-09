<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Api\OrderPostprocessingShopMatchInterface;

/**
 * The shop-match checks (TWO-26276). There is one: a shipping line whose rate
 * the shipping tax fallback supplied must carry the tax Magento charged at
 * that rate (TWO-26117). It applies to the shipping line the plugin composed,
 * while the payload still carries that line unchanged.
 *
 * Refused in the wording the builder used: the buyer's for intent, create and
 * update, which ComposeOrder composes; the merchant's for a capture.
 */
class ShopMatchChecks implements OrderPostprocessingShopMatchInterface
{
    private const SHIPPING_LINE = 'shipping';

    /**
     * @var ComposeOrder
     */
    private $composeOrder;

    /**
     * @var ComposeCapture
     */
    private $composeCapture;

    public function __construct(ComposeOrder $composeOrder, ComposeCapture $composeCapture)
    {
        $this->composeOrder = $composeOrder;
        $this->composeCapture = $composeCapture;
    }

    /**
     * @inheritDoc
     */
    public function check(array $result, array $payload, array $context): void
    {
        $requestType = (string)($context['request_type'] ?? '');
        $composed = $this->shippingLine($this->lines($payload, $requestType));
        if ($composed === null || !$this->carries($this->lines($result, $requestType), $composed)) {
            return;
        }

        switch ($requestType) {
            case Hook::REQUEST_ORDER_INTENT:
                $entity = $context['intent_order'] ?? null;
                $service = $this->composeOrder;
                break;
            case Hook::REQUEST_ORDER_CREATE:
            case Hook::REQUEST_ORDER_UPDATE:
                $entity = $context['order'] ?? null;
                $service = $this->composeOrder;
                break;
            case Hook::REQUEST_CAPTURE:
                // An invoice composes its own shipping line; a shipment composes the order's.
                $entity = ($context['invoice'] ?? null) instanceof Invoice ? $context['invoice'] : ($context['order'] ?? null);
                $service = $this->composeCapture;
                break;
            default:
                // A refund relays its parent line's rate unchecked; the rest carry no lines.
                return;
        }

        if ($entity instanceof Order || $entity instanceof Invoice) {
            $service->assertShippingTaxFallback($entity);
        }
    }

    /**
     * @param array $payload
     * @param string $requestType
     * @return array
     */
    private function lines(array $payload, string $requestType): array
    {
        $lines = $requestType === Hook::REQUEST_CAPTURE
            ? ($payload['partial']['line_items'] ?? [])
            : ($payload['line_items'] ?? []);

        return is_array($lines) ? $lines : [];
    }

    /**
     * @param array $lines
     * @return array|null
     */
    private function shippingLine(array $lines): ?array
    {
        foreach ($lines as $line) {
            if (is_array($line) && ($line['order_item_id'] ?? null) === self::SHIPPING_LINE) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Whether $lines still carry $line exactly, whatever the key order.
     *
     * @param array $lines
     * @param array $line
     * @return bool
     */
    private function carries(array $lines, array $line): bool
    {
        $line = $this->canonical($line);
        foreach ($lines as $candidate) {
            if (is_array($candidate) && $this->canonical($candidate) === $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $value
     * @return array
     */
    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
