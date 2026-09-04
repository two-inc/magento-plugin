<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Setup\Uninstall;

/**
 * TWO-25386: clear stored settings on module uninstall — Magento's
 * nearest equivalent lifecycle event for this. Default off: uninstall must
 * leave configuration in place unless the merchant explicitly opted in.
 *
 * Adversarial review (round 1) found the first version of this class
 * hardcoded `payment/two_payment/%`, ignoring the active brand's own code
 * — on a brand overlay this both silently no-ops the opt-in AND leaves a
 * dead `payment/two_search/%` clause that never matched anything (the
 * "Search" admin section's fields live under `payment/<code>/*` too, same
 * as everything else). This test pins the brand-code-derived behaviour and
 * the LIKE-escaping fix that went with it.
 *
 * SchemaSetupInterface/ModuleContextInterface are auto-stubbed as EMPTY
 * interfaces by Test/bootstrap.php's catch-all (no real Magento framework
 * installed in this harness), so createMock() cannot configure methods
 * that do not exist on them. A hand-written fake, same pattern as
 * Test\Unit\Setup\Patch\Data\CollapseAddressSearchToggleTest, stands in.
 */
class UninstallTest extends TestCase
{
    private function buildUninstall(bool $flagValue, string $code): Uninstall
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('payment/' . $code . '/clear_settings_on_uninstall', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->willReturn($flagValue);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn($code);

        return new Uninstall($scopeConfig, $brandRegistry);
    }

    public function testDoesNothingWhenToggleIsOff(): void
    {
        $uninstall = $this->buildUninstall(false, 'two_payment');
        $setup = new FakeSchemaSetup();

        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertFalse($setup->setupStarted, 'startSetup() must not run when the toggle is off');
        $this->assertSame([], $setup->connection->deletedPatterns);
    }

    public function testDeletesOnlyTheActiveBrandsConfigWhenToggleIsOn(): void
    {
        $uninstall = $this->buildUninstall(true, 'two_payment');
        $setup = new FakeSchemaSetup();

        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertTrue($setup->setupStarted);
        $this->assertTrue($setup->setupEnded);
        // No separate 'two_search' clause: that admin section's fields are
        // stored under payment/<code>/* like everything else, so one
        // pattern covers them too.
        $this->assertSame(['payment/two\\_payment/%'], $setup->connection->deletedPatterns);
    }

    public function testUsesTheActiveBrandsOwnCodeNotTheVanillaDefault(): void
    {
        $uninstall = $this->buildUninstall(true, 'acmepayment');
        $setup = new FakeSchemaSetup();

        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertSame(['payment/acmepayment/%'], $setup->connection->deletedPatterns);
    }

    public function testEscapesLikeSpecialCharactersInTheBrandCode(): void
    {
        // "two_payment" contains a literal underscore, a LIKE wildcard.
        // Unescaped, 'payment/two_payment/%' would also match
        // 'payment/twoXpayment/anything' for any single character X.
        $uninstall = $this->buildUninstall(true, 'two_payment');
        $setup = new FakeSchemaSetup();

        $uninstall->uninstall($setup, new FakeModuleContext());

        $this->assertSame(['payment/two\\_payment/%'], $setup->connection->deletedPatterns);
    }
}

class FakeConnection
{
    /** @var string[] */
    public $deletedPatterns = [];

    public function quoteInto($text, $value)
    {
        // Faithful enough for this test: capture the escaped pattern that
        // was built, without needing a real DB adapter's quoting.
        return ['sql' => $text, 'pattern' => $value];
    }

    public function delete($table, array $where)
    {
        foreach ($where as $condition) {
            if (is_array($condition) && isset($condition['pattern'])) {
                $this->deletedPatterns[] = $condition['pattern'];
            }
        }
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
