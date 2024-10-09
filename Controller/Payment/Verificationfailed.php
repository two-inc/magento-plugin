<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

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
        try {
            $order = $this->orderService->getOrderByReference();
            $message = __(
                'Your invoice purchase with %1 failed verification. The cart will be restored.',
                $this->configRepository::PROVIDER
            );
            throw new LocalizedException($message);
        } catch (Exception $exception) {
            $this->orderService->restoreQuote();
            if (isset($order)) {
                $this->orderService->failOrder($order, $exception->getMessage());
            }

            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
        }
    }
}
