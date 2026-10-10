<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Invoice;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Tells the merchant when an invoice was recorded in Magento only (TWO-26302).
 *
 * With a fulfilment trigger other than "invoice" the method cannot capture,
 * so the admin invoice form submits an offline capture and core records the
 * invoice as Paid without calling the provider. That is allowed: the order is
 * fulfilled with the provider later, at the configured trigger. This adds a
 * merchant-only order comment saying so.
 *
 * Listens on sales_order_invoice_register, which core dispatches once per
 * invoice from Invoice::register() (a second register() throws). The
 * plugin's own fulfilment flows also register offline invoices, after the
 * provider has been told, and flag them with FULFILLED_WITH_PROVIDER so no
 * comment is added for those.
 *
 * The wording follows the trigger: on shipment, or on reaching one of the
 * configured fulfil-on statuses, named by their labels. The status-change
 * fulfilment still runs after an invoice recorded here, so both promises
 * hold.
 */
class InvoiceRegisteredOffline implements ObserverInterface
{
    /** Invoice data key set by the plugin's own fulfilment flows. */
    public const FULFILLED_WITH_PROVIDER = 'two_fulfilled_with_provider';

    /** @var ConfigRepository */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /** @var OrderConfig */
    private $orderConfig;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        BrandOverlayRegistryInterface $overlayRegistry,
        OrderConfig $orderConfig
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->overlayRegistry = $overlayRegistry;
        $this->orderConfig = $orderConfig;
    }

    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getInvoice();
        if (!$invoice
            || $invoice->getRequestedCaptureCase() !== Invoice::CAPTURE_OFFLINE
            || $invoice->getData(self::FULFILLED_WITH_PROVIDER)
        ) {
            return;
        }
        $order = $invoice->getOrder();
        $payment = $order ? $order->getPayment() : null;
        if (!$payment || !$this->overlayRegistry->isTwoStackMethod((string)$payment->getMethod())) {
            return;
        }

        // Same store-agnostic read as Two::canCapture() and the fulfilment
        // observers, so the comment matches what will actually happen.
        $comment = $this->commentFor($this->configRepository->getFulfillTrigger());
        if ($comment === null) {
            return;
        }

        $order->addCommentToStatusHistory($comment->render(), false, false)
            ->setIsCustomerNotified(false);
    }

    private function commentFor(string $trigger): ?Phrase
    {
        $productName = $this->brandRegistry->getProductName();
        switch ($trigger) {
            case 'shipment':
                return __(
                    'This invoice was recorded in Magento only and %1 was not notified. The order will be fulfilled with %1 when it is shipped.',
                    $productName
                );
            case 'complete':
                // With no fulfil-on status configured nothing will fulfil
                // the order, so there is nothing true to promise.
                $statuses = $this->statusLabels();
                return $statuses === '' ? null : __(
                    'This invoice was recorded in Magento only and %1 was not notified. The order will be fulfilled with %1 when its status changes to %2.',
                    $productName,
                    $statuses
                );
            default:
                return null;
        }
    }

    /**
     * Labels of the configured fulfil-on statuses, falling back to the code.
     */
    private function statusLabels(): string
    {
        $labels = [];
        foreach ($this->configRepository->getFulfillOrderStatusList() as $code) {
            if ($code === '') {
                continue;
            }
            $labels[] = (string)($this->orderConfig->getStatusLabel($code) ?: $code);
        }

        return implode(', ', $labels);
    }
}
