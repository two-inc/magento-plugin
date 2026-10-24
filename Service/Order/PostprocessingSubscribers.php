<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;
use Two\Gateway\Model\OrderPostprocessing;

/**
 * Who else handles the order postprocessing hook (TWO-26276).
 *
 * Read from the interception config the generated interceptor itself runs
 * on: the shared PluginList, at the area the request runs in (frontend,
 * adminhtml, webapi_rest, crontab and the rest), walked the way the
 * interceptor walks it. A disabled plugin, or one in a disabled module or
 * another area's di.xml, is not in that list and does not run, so it does
 * not count. A preference that replaces the default implementation counts
 * too: it can change the payload without any plugin.
 */
class PostprocessingSubscribers
{
    /** The plugin's own default handler, as named in etc/di.xml. */
    public const DEFAULT_HANDLER = 'two_gateway_shop_match_checks';

    private const METHOD = 'process';

    /**
     * @var PluginListInterface
     */
    private $pluginList;

    public function __construct(PluginListInterface $pluginList)
    {
        $this->pluginList = $pluginList;
    }

    /**
     * Every handler on process() other than the plugin's own, by plugin name.
     *
     * @param object $subject The hook instance the plugins run on: the generated interceptor.
     * @return array<string, string> Plugin name, or "preference", to class name.
     */
    public function merchantHandlers(object $subject): array
    {
        $type = $subject instanceof InterceptorInterface ? get_parent_class($subject) : get_class($subject);
        $handlers = [];
        if ($type !== OrderPostprocessing::class) {
            $handlers['preference'] = $type;
        }

        foreach ($this->pluginNames($type) as $name) {
            if ($name !== self::DEFAULT_HANDLER) {
                $handlers[$name] = $this->pluginClass($type, $name);
            }
        }

        return $handlers;
    }

    /**
     * Before, around and after plugins on process(), following each around plugin to the next stage.
     *
     * @param string $type
     * @return string[]
     */
    private function pluginNames(string $type): array
    {
        $names = [];
        $code = '__self';
        while (true) {
            $next = $this->pluginList->getNext($type, self::METHOD, $code);
            if (!is_array($next)) {
                break;
            }
            foreach ([DefinitionInterface::LISTENER_BEFORE, DefinitionInterface::LISTENER_AFTER] as $listener) {
                foreach ($next[$listener] ?? [] as $name) {
                    $names[] = (string)$name;
                }
            }
            $around = $next[DefinitionInterface::LISTENER_AROUND] ?? null;
            if ($around === null || in_array((string)$around, $names, true)) {
                break;
            }
            $names[] = $code = (string)$around;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param string $type
     * @param string $name
     * @return string
     */
    private function pluginClass(string $type, string $name): string
    {
        $plugin = $this->pluginList->getPlugin($type, $name);

        return is_object($plugin) ? get_class($plugin) : (string)$plugin;
    }
}
