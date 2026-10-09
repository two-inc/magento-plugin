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
use Two\Gateway\Plugin\OrderPostprocessing\ShopMatchDefaultHandler;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingSubscribers;
use Two\Gateway\Service\Order\ShopMatchChecks;

/**
 * A real OrderPostprocessor; by default with no subscriber, the hook's own binding. hookChain()
 * adds the plugin's default handler and any subscribers, as etc/di.xml and a merchant's module do.
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

    /**
     * The hook as the interceptor runs it: the plugin's default handler first, then each subscriber.
     *
     * @param LogRepository $log
     * @param array<string, object> $subscribers plugin name => after plugin
     * @return HookChain
     */
    private function hookChain(LogRepository $log, array $subscribers = []): HookChain
    {
        $chain = new HookChain();
        $chain->add(PostprocessingSubscribers::DEFAULT_HANDLER, new ShopMatchDefaultHandler(
            $this->shopMatchChecks($log),
            new PostprocessingSubscribers($chain->pluginList()),
            $log
        ));
        foreach ($subscribers as $name => $subscriber) {
            $chain->add($name, $subscriber);
        }

        return $chain;
    }

    /**
     * The real shop-match checks, over real composers that log to $log.
     */
    private function shopMatchChecks(LogRepository $log): ShopMatchChecks
    {
        $composers = [];
        foreach ([ComposeOrder::class, ComposeCapture::class] as $class) {
            $composer = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            (new \ReflectionProperty(OrderService::class, 'logRepository'))->setValue($composer, $log);
            $composers[] = $composer;
        }

        return new ShopMatchChecks(...$composers);
    }
}
