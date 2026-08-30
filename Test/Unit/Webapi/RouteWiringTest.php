<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Webapi;

use PHPUnit\Framework\TestCase;

/**
 * etc/webapi.xml is resolved by the framework, never by PHPUnit, so a typo in
 * a service class, a method that no longer exists, or a missing di.xml
 * preference is a checkout 404 with a green suite behind it.
 */
class RouteWiringTest extends TestCase
{
    /**
     * Given a registered route; When the framework resolves it; Then the
     * declared interface, its method and the concrete class di.xml binds it
     * to all exist.
     *
     * @dataProvider registeredRoutes
     */
    public function testEachRouteResolvesToACallableImplementation(
        string $interface,
        string $method,
        string $description
    ): void {
        $this->assertTrue(interface_exists($interface), $description . ': no such service interface');
        $this->assertTrue(
            method_exists($interface, $method),
            sprintf('%s: %s::%s is not declared', $description, $interface, $method)
        );

        $concrete = self::preferences()[$interface] ?? null;
        $this->assertNotNull($concrete, $description . ': etc/di.xml declares no preference');
        $this->assertTrue(class_exists($concrete), $description . ': preferred class is not on disk');
        $this->assertContains($interface, class_implements($concrete), $description);
    }

    /**
     * A route that silently loses its `<resources>` declaration becomes
     * unroutable, and a typo'd ref is the same outage — so the ref must be one
     * the framework actually resolves. Tightening `anonymous` to a real ACL
     * stays allowed: the set is what is legal, not one fixed value.
     *
     * @dataProvider registeredRoutes
     */
    public function testEachRouteDeclaresAResolvableAccessResource(
        string $interface,
        string $method,
        string $description,
        string $resource
    ): void {
        $this->assertContains(
            $resource,
            self::KNOWN_RESOURCES,
            $description . ': unknown <resource ref>, which the framework resolves to no route at all'
        );
    }

    /**
     * Refs the framework resolves for this module's routes: the guest-checkout
     * pseudo-resources, and the ACLs a merchant may tighten a route to.
     */
    private const KNOWN_RESOURCES = [
        'anonymous',
        'self',
        'Magento_Sales::sales',
        'Magento_Sales::actions_view',
        'Magento_Cart::cart',
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function registeredRoutes(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/webapi.xml');

        $cases = [];
        foreach ($xml->route as $route) {
            $label = (string)$route['method'] . ' ' . (string)$route['url'];
            $cases[$label] = [
                (string)$route->service['class'],
                (string)$route->service['method'],
                $label,
                (string)($route->resources->resource['ref'] ?? ''),
            ];
        }

        return $cases;
    }

    /**
     * @return array<string,string> interface => concrete class
     */
    private static function preferences(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');

        $map = [];
        foreach ($xml->preference as $preference) {
            $map[(string)$preference['for']] = (string)$preference['type'];
        }

        return $map;
    }
}
