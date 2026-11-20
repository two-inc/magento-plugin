<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Interception\DefinitionInterface as Listener;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Model\OrderPostprocessing;
use Two\Gateway\Service\Order\PostprocessingSubscribers;

/**
 * Which handlers on the postprocessing hook are not the plugin's own
 * (TWO-26276), read from the plugin list the interceptor runs on. A disabled
 * plugin, a disabled module's plugin and another area's plugin never reach
 * that list, which is Magento's own config merge: the CI probe covers it in a
 * real shop, per area.
 */
class PostprocessingSubscribersTest extends TestCase
{
    private const OWN = PostprocessingSubscribers::DEFAULT_HANDLER;

    /**
     * @param array<string, array|null> $chain getNext() answers keyed by code, '__self' first
     * @param bool $intercepted the subject is the generated interceptor rather than the class itself
     * @param bool $replaced a preference other than the plugin's default implementation
     * @param array<string, string> $expected
     * @dataProvider detectionCases
     */
    public function testMerchantHandlersAreEveryHandlerButTheDefault(
        array $chain,
        bool $intercepted,
        bool $replaced,
        array $expected,
        string $description
    ): void {
        $subject = $replaced ? new ReplacementHook() : ($intercepted ? new InterceptedHook() : new OrderPostprocessing());
        $type = get_class($replaced ? $subject : new OrderPostprocessing());

        $pluginList = $this->createMock(PluginListInterface::class);
        $pluginList->method('getNext')->willReturnCallback(
            static fn ($forType, $method, $code) => $forType === $type && $method === 'process' ? ($chain[$code] ?? null) : null
        );
        $pluginList->method('getPlugin')->willReturnCallback(
            static fn ($forType, $code) => new \ArrayObject(['plugin' => $code])
        );

        $this->assertSame($expected, (new PostprocessingSubscribers($pluginList))->merchantHandlers($subject), $description);
    }

    public static function detectionCases(): array
    {
        $after = static fn (string ...$names): array => [Listener::LISTENER_AFTER => $names];
        $plugin = \ArrayObject::class;

        return [
            [[], true, false, [], 'no plugin at all'],
            [['__self' => $after(self::OWN)], true, false, [], 'only the plugin\'s own default handler'],
            [['__self' => $after(self::OWN, 'acme')], true, false, ['acme' => $plugin], 'a merchant after plugin beside it'],
            [['__self' => $after('acme', self::OWN, 'other')], true, false, ['acme' => $plugin, 'other' => $plugin], 'two merchant plugins, sorted either side'],
            [['__self' => [Listener::LISTENER_BEFORE => ['acme']] + $after(self::OWN)], true, false, ['acme' => $plugin], 'a before plugin'],
            [['__self' => [Listener::LISTENER_AROUND => 'acme'], 'acme' => $after(self::OWN)], true, false, ['acme' => $plugin], 'an around plugin, and what it chains to'],
            [['__self' => [Listener::LISTENER_AROUND => 'acme'] + $after(self::OWN), 'acme' => [Listener::LISTENER_AROUND => 'acme']], true, false, ['acme' => $plugin], 'a chain that loops is read once'],
            [['__self' => $after(self::OWN)], true, false, [], 'a merchant plugin disabled, or its module or area inactive: Magento lists only ours'],
            [['__self' => $after(self::OWN)], false, false, [], 'the class itself, not intercepted'],
            [['__self' => $after(self::OWN)], true, true, ['preference' => ReplacementHook::class], 'a preference replacing the default implementation'],
            [['__self' => $after(self::OWN, 'acme')], true, true, ['preference' => ReplacementHook::class, 'acme' => $plugin], 'a replacement and a plugin'],
        ];
    }
}

/**
 * The generated interceptor's shape: a subclass of the bound type.
 */
class InterceptedHook extends OrderPostprocessing implements InterceptorInterface
{
}

/**
 * A merchant's own binding for the hook.
 */
class ReplacementHook implements OrderPostprocessingInterface
{
    public function process(array $payload, array $context): array
    {
        return $payload;
    }
}
