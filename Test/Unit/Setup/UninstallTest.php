<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Uninstall;

/**
 * TWO-25386: ported from woocommerce-plugin's "clear settings on
 * deactivation" — Magento's nearest equivalent lifecycle event is module
 * uninstall. Default off: uninstall must leave configuration in place
 * unless the merchant explicitly opted in.
 *
 * SchemaSetupInterface/ModuleContextInterface are auto-stubbed as EMPTY
 * interfaces by Test/bootstrap.php's catch-all (no real Magento framework
 * installed in this harness), so createMock() cannot configure methods
 * that do not exist on them. A hand-written fake, same pattern as
 * Test\Unit\Setup\Patch\Data\CollapseAddressSearchToggleTest, stands in.
 */
class UninstallTest extends TestCase
{
    public function testDoesNothingWhenToggleIsOff(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('payment/two_payment/clear_settings_on_uninstall')
            ->willReturn(false);

        $setup = new FakeSchemaSetup();

        $uninstall = new Uninstall($scopeConfig);
        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertFalse($setup->setupStarted, 'startSetup() must not run when the toggle is off');
        $this->assertSame([], $setup->connection->deletedPaths);
    }

    public function testDeletesTwoConfigWhenToggleIsOn(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('payment/two_payment/clear_settings_on_uninstall')
            ->willReturn(true);

        $setup = new FakeSchemaSetup();

        $uninstall = new Uninstall($scopeConfig);
        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertTrue($setup->setupStarted);
        $this->assertTrue($setup->setupEnded);
        $this->assertSame(
            ['payment/two_payment/%', 'payment/two_search/%'],
            $setup->connection->deletedPaths
        );
    }
}

class FakeConnection
{
    /** @var string[] */
    public $deletedPaths = [];

    public function delete($table, array $where)
    {
        $this->deletedPaths[] = $where['path LIKE ?'];
        return 1;
    }
}

class FakeSchemaSetup implements SchemaSetupInterface
{
    public $setupStarted = false;
    public $setupEnded = false;

    /** @var FakeConnection */
    public $connection;

    public function __construct()
    {
        $this->connection = new FakeConnection();
    }

    public function startSetup()
    {
        $this->setupStarted = true;
        return $this;
    }

    public function endSetup()
    {
        $this->setupEnded = true;
        return $this;
    }

    public function getConnection($resourceName = 'default')
    {
        return $this->connection;
    }

    public function getTable($tableName, $moduleName = 'Two_Gateway')
    {
        return $tableName;
    }
}

class FakeModuleContext implements ModuleContextInterface
{
    public function getVersion()
    {
        return '2.2.1';
    }
}
