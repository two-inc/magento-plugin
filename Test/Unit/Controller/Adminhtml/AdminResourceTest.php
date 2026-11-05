<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Adminhtml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TWO-27057: every admin controller of this module names the ACL resource that
 * guards it. Without its own ADMIN_RESOURCE a controller falls back to the
 * backend default, which any admin role holds.
 */
class AdminResourceTest extends TestCase
{
    private const RESOURCE = 'Magento_Sales::config_sales';

    public static function controllers(): array
    {
        $root = dirname(__DIR__, 4) . '/Controller/Adminhtml';
        $rows = [];
        foreach (glob($root . '/*/*.php') as $path) {
            $relative = substr($path, strlen($root) + 1, -4);
            $class = 'Two\\Gateway\\Controller\\Adminhtml\\' . str_replace('/', '\\', $relative);
            $rows[$relative] = [$class];
        }
        return $rows;
    }

    #[DataProvider('controllers')]
    public function testTheControllerDeclaresItsAclResource(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('ADMIN_RESOURCE');

        $description = $this->dataName();
        $this->assertNotFalse($constant, "$description: ADMIN_RESOURCE is declared");
        $this->assertSame($class, $constant->getDeclaringClass()->getName(), "$description: declared on the class itself");
        $this->assertSame(self::RESOURCE, $constant->getValue(), "$description: resource");
    }
}
