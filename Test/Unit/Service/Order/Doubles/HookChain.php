<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;
use Two\Gateway\Model\OrderPostprocessing;

/**
 * Stands in for the generated interceptor on the postprocessing hook: the
 * default implementation, then each `after` plugin in the order added, under
 * the default's type. pluginList() reports the same plugins the way
 * Magento's PluginList does, so subscriber detection sees what runs.
 */
class HookChain extends OrderPostprocessing implements InterceptorInterface
{
    /** @var array<string, object> */
    private $plugins = [];

    /**
     * @param string $name
     * @param object $plugin An object with afterProcess().
     * @return $this
     */
    public function add(string $name, object $plugin): self
    {
        $this->plugins[$name] = $plugin;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function process(array $payload, array $context): array
    {
        $result = parent::process($payload, $context);
        foreach ($this->plugins as $plugin) {
            $result = $plugin->afterProcess($this, $result, $payload, $context);
        }

        return $result;
    }

    public function pluginList(): PluginListInterface
    {
        $plugins = &$this->plugins;

        return new class ($plugins) implements PluginListInterface {
            /** @var array<string, object> */
            private $plugins;

            public function __construct(array &$plugins)
            {
                $this->plugins = &$plugins;
            }

            public function getNext($type, $method, $code = null)
            {
                $owns = $type === OrderPostprocessing::class && $method === 'process' && $code === '__self';

                return $owns && $this->plugins ? [DefinitionInterface::LISTENER_AFTER => array_keys($this->plugins)] : null;
            }

            public function getPlugin($type, $code)
            {
                return $this->plugins[$code];
            }
        };
    }
}
