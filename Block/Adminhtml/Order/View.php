<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Service\Api\Adapter as Adapter;

/**
 * Order View Block
 */
class View extends OrderView
{
    /**
    * @var ConfigRepository
     */
    public $configRepository;

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
        Adapter $apiAdapter,
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Model\Config $salesConfig,
        \Magento\Sales\Helper\Reorder $reorderHelper,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
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
            'GET'
        );
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        if ($error) {
            return [];
        }

        return $response;
    }

    /**
     * Get Two Order ID
     *
     * @param array $data
     *
     * @return string
     */
    public function getTwoOrderId(): string
    {
        return $this->getOrder()->getTwoOrderId();
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
}
