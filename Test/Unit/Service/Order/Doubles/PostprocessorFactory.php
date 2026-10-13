<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
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
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ShopMatchChecks;
use Two\Gateway\Service\Order\ShopMatchRefusals;
use Two\Gateway\Service\Order\TaxCodeResolver;

/**
 * A real OrderPostprocessor; by default with no subscriber, the hook's own binding. hookChain()
 * adds the plugin's default handler and any subscribers, as etc/di.xml and a merchant's module do.
 */
trait PostprocessorFactory
{
    /** @var int|null the prepaid Two holds for the order, in cents; null: the order's own tenders (a new order) */
    protected $twoPrepaid = null;

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

        $hook = $hook ?? new OrderPostprocessing();

        return new OrderPostprocessor(
            $orderService,
            $config,
            $log,
            $hook,
            new PostprocessingSubscribers($hook instanceof HookChain ? $hook->pluginList() : $this->emptyPluginList()),
            $this->shopMatchRefusals(),
            $this->twoAdapter ?? $this->createMock(Adapter::class),
            $this->createMock(TaxCodeResolver::class)
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
            // The plugin's own tender reader, as DI wires it (TWO-26309).
            (new \ReflectionProperty(OrderService::class, 'tenderReader'))
                ->setValue($composer, new \Two\Gateway\Service\Order\TenderReader(new \Two\Gateway\Model\OrderTenders(), $log));
            (new \ReflectionProperty(OrderService::class, 'prepaidLedger'))->setValue($composer, $this->ledgerStandIn($composer));
            $composers[] = $composer;
        }

        return new ShopMatchChecks($composers[0], $composers[1], $log, $this->shopMatchRefusals());
    }

    /** Two's record of the order: its prepaid as $this->twoPrepaid says, nothing fulfilled yet. */
    private function ledgerStandIn(OrderService $composer): \Two\Gateway\Service\Order\PrepaidLedger
    {
        $test = $this;

        return new class ($test, $composer) extends \Two\Gateway\Service\Order\PrepaidLedger {
            private $test;
            private $composer;

            public function __construct($test, $composer)
            {
                $this->test = $test;
                $this->composer = $composer;
            }

            public function read(\Magento\Sales\Model\Order $order): array
            {
                $prepaid = $this->test->twoPrepaidFor($order, $this->composer);

                return ['invoice' => 0, 'prepaid' => $prepaid, 'fulfilled_invoice' => 0, 'fulfilled_prepaid' => 0,
                    'refunded_prepaid' => 0, 'refunded_amount' => 0];
            }
        };
    }

    public function twoPrepaidFor(\Magento\Sales\Model\Order $order, OrderService $composer): int
    {
        return $this->twoPrepaid ?? (int)round($composer->getTenderTotal($order) * 100);
    }

    /** One record per test, shared by the checks and the postprocessor as DI shares it. */
    private function shopMatchRefusals(): ShopMatchRefusals
    {
        static $refusals = [];
        $key = spl_object_id($this);

        return $refusals[$key] ??= new ShopMatchRefusals();
    }

    private function emptyPluginList(): \Magento\Framework\Interception\PluginListInterface
    {
        return (new HookChain())->pluginList();
    }
}
