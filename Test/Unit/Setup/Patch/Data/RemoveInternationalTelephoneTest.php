<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\InternationalTelephone;
use Two\Gateway\Setup\Patch\Data\RemoveInternationalTelephone;

/**
 * Coverage for the removal of the orphaned two_telephone
 * customer_address EAV attribute (TWO-24868): apply() must remove
 * exactly that attribute, and the patch must be ordered after the
 * InternationalTelephone patch that introduced it.
 */
class RemoveInternationalTelephoneTest extends TestCase
{
    /** @var RecordingEavSetup */
    private $eavSetup;

    /** @var RemoveInternationalTelephone */
    private $patch;

    protected function setUp(): void
    {
        $moduleDataSetup = new class implements ModuleDataSetupInterface {
            public function getConnection()
            {
                return new class {
                    public function startSetup(): void
                    {
                    }

                    public function endSetup(): void
                    {
                    }
                };
            }

            public function getTable($tableName)
            {
                return $tableName;
            }
        };

        $this->eavSetup = new RecordingEavSetup();
        $eavSetup = $this->eavSetup;

        $eavSetupFactory = new class($eavSetup) extends EavSetupFactory {
            /** @var RecordingEavSetup */
            private $eavSetup;

            public function __construct($eavSetup)
            {
                $this->eavSetup = $eavSetup;
            }

            public function create(array $data = [])
            {
                return $this->eavSetup;
            }
        };

        $this->patch = new RemoveInternationalTelephone($moduleDataSetup, $eavSetupFactory);
    }

    public function testApplyRemovesTwoTelephoneCustomerAddressAttribute(): void
    {
        $this->patch->apply();

        $this->assertSame(
            [['customer_address', 'two_telephone']],
            $this->eavSetup->removedAttributes
        );
    }

    public function testGetDependenciesReturnsInternationalTelephone(): void
    {
        $this->assertSame(
            [InternationalTelephone::class],
            RemoveInternationalTelephone::getDependencies()
        );
    }

    public function testGetAliasesReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->patch->getAliases());
    }
}

/**
 * Records removeAttribute() calls so the test can assert on the exact
 * entity type / attribute code pair the patch removes.
 */
class RecordingEavSetup extends \Magento\Eav\Setup\EavSetup
{
    /** @var array<int, array{0: string, 1: string}> */
    public $removedAttributes = [];

    public function __construct()
    {
    }

    public function removeAttribute($entityTypeId, $code): self
    {
        $this->removedAttributes[] = [$entityTypeId, $code];
        return $this;
    }
}
