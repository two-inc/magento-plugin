<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Model\OrderPostprocessing;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;

/**
 * A real OrderPostprocessor; by default with no subscriber, the hook's own binding.
 */
trait PostprocessorFactory
{
    private function buildPostprocessor(
        ?Hook $hook = null,
        ?ConfigRepository $config = null,
        ?LogRepository $log = null,
        ?OrderStatusHistoryRepositoryInterface $historyRepository = null,
        float $shippingRatePercent = 0.0
    ): OrderPostprocessor {
        $config = $config ?? $this->createMock(ConfigRepository::class);
        $log = $log ?? $this->createMock(LogRepository::class);
        $taxCalculation = $this->createMock(TaxCalculation::class);
        $taxCalculation->method('getRateRequest')->willReturn(new DataObject());
        $taxCalculation->method('getRate')->willReturn($shippingRatePercent);

        $orderService = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $orderService->configRepository = $config;
        foreach ([
            'logRepository' => $log,
            'taxCalculation' => $taxCalculation,
            'groupRepository' => $this->createMock(GroupRepositoryInterface::class),
        ] as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($orderService, $value);
        }

        return new OrderPostprocessor(
            $orderService,
            $config,
            $log,
            $hook ?? new OrderPostprocessing(),
            new HistoryFactory(),
            $historyRepository ?? $this->createMock(OrderStatusHistoryRepositoryInterface::class)
        );
    }
}
