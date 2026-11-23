<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Api\Adapter as Adapter;

/**
 * Order View Block
 */
class View extends OrderView
{
    /**
     * Each read to Two on the order view is bounded, so a slow or hung Two
     * cannot stall the merchant's order page (TWO-27371). A read that runs
     * out leaves its rows off; the page still renders.
     */
    private const READ_TIMEOUT_SECONDS = 5;

    /**
     * Two's order states as the merchant reads them. A state not listed is
     * shown as Two names it.
     */
    private const STATE_LABELS = [
        'UNVERIFIED' => 'Unverified',
        'VERIFIED' => 'Verified',
        'CONFIRMED' => 'Confirmed',
        'FULFILLING' => 'Fulfilling',
        'FULFILLED' => 'Fulfilled',
        'DELIVERED' => 'Delivered',
        'CANCELLED' => 'Cancelled',
        'REFUNDED' => 'Refunded',
    ];

    /**
    * @var ConfigRepository
     */
    public $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * View constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $adapter
     * @param \Magento\Backend\Block\Widget\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Sales\Model\Config $salesConfig
     * @param \Magento\Sales\Helper\Reorder $reorderHelper
     * @param array $data
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Adapter $apiAdapter,
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Model\Config $salesConfig,
        \Magento\Sales\Helper\Reorder $reorderHelper,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->apiAdapter = $apiAdapter;
        parent::__construct($context, $registry, $salesConfig, $reorderHelper, $data);
    }

    /**
     * Get Two Fulfillments
     *
     * @param array $data
     *
     * @return array
     */
    public function getTwoOrderFulfillments(): array
    {
        $order = $this->getOrder();
        $response = $this->apiAdapter->execute(
            "/v1/order/" . $order->getTwoOrderId() . "/fulfillments",
            [],
            'GET',
            (int)$order->getStoreId(),
            null,
            null,
            self::READ_TIMEOUT_SECONDS
        );
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        if ($error) {
            return [];
        }

        return $response;
    }

    /**
     * What Two knows about the order before anything is fulfilled
     * (TWO-27371): its id, its live state and the term it was placed on.
     * The state is read from Two and left out when Two cannot be reached;
     * the term is the one stored at placement.
     *
     * @return array{id: string, state: ?string, term: ?string}|null null when
     *         the order never reached Two
     */
    public function getTwoOrderSummary(): ?array
    {
        $order = $this->getOrder();
        $twoOrderId = (string)$order->getTwoOrderId();
        if ($twoOrderId === '') {
            return null;
        }

        $response = $this->apiAdapter->execute(
            '/v1/order/' . $twoOrderId,
            [],
            'GET',
            (int)$order->getStoreId(),
            null,
            null,
            self::READ_TIMEOUT_SECONDS
        );
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        $state = $error === null && is_string($response['state'] ?? null) ? $response['state'] : null;

        return ['id' => $twoOrderId, 'state' => $this->stateLabel($state), 'term' => $this->placedTermLabel()];
    }

    /**
     * Two's state as a translated label, or as Two names it when unlisted.
     */
    private function stateLabel(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        return isset(self::STATE_LABELS[$state]) ? (string)__(self::STATE_LABELS[$state]) : $state;
    }

    /**
     * The payment term stored with the order at placement, as a label.
     */
    private function placedTermLabel(): ?string
    {
        $terms = $this->getOrder()->getPayment()->getAdditionalInformation('terms');
        $days = is_array($terms) ? (int)($terms['duration_days'] ?? 0) : 0;
        if ($days <= 0) {
            return null;
        }
        $label = ($terms['duration_days_calculated_from'] ?? null) === 'END_OF_MONTH'
            ? __('%1 days from end of month', $days)
            : __('%1 days', $days);

        return (string)$label;
    }

    /**
     * Get Method from Payment
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->getOrder()->getPayment()->getMethod();
    }

    /**
     * Brand-bound product name for use in templates ($block->getProductName()).
     */
    public function getProductName(): string
    {
        return $this->brandRegistry->getProductName();
    }
}
