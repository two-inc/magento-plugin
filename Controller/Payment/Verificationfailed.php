<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Controller\Payment;

use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use ABN\Gateway\Service\Payment\OrderService;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Verification failed controller
 */
class Verificationfailed extends Action
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * Verificationfailed constructor.
     *
     * @param OrderService $orderService
     * @param Context $context
     */
    public function __construct(
        ConfigRepository $configRepository,
        OrderService $orderService,
        Context $context
    ) {
        $this->configRepository = $configRepository;
        $this->orderService = $orderService;
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $order = null;
        try {
            $order = $this->orderService->getOrderByReference();
            $message = __(
                'Your invoice purchase with %1 failed verification. The cart will be restored.',
                $this->configRepository::PRODUCT_NAME
            );
            throw new LocalizedException($message);
        } catch (Exception $exception) {
            $this->orderService->restoreQuote();
            if ($order !== null) {
                $this->orderService->failOrder($order, $exception->getMessage());
            }
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
        }
    }
}
