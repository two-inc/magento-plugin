<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\TaxClassManagementInterface;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * Provision an always-zero Product Tax Class for the surcharge.
 *
 * Magento ships no out-of-the-box "None"/0% Product Tax Class, so a
 * merchant who wants a guaranteed-untaxed surcharge would otherwise
 * have to create one by hand. This patch inserts
 * "Payment Terms Surcharge - No Tax" (SurchargeTaxCalculator::NO_TAX_CLASS_NAME)
 * with NO Tax Rule attached: the tax engine resolves no rule for it in
 * any jurisdiction, so selecting it in the Surcharge Tax Class dropdown
 * yields zero surcharge tax for every destination.
 *
 * SurchargeTaxCalculator logs an error if this class ever resolves
 * non-zero tax (i.e. someone attached a Tax Rule to it later).
 *
 * Inserts via the tax_class table directly — the same shape core's own
 * install data uses — keyed idempotently on (class_name, class_type).
 */
class SurchargeNoTaxClass implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('tax_class');

        $exists = $connection->fetchOne(
            $connection->select()
                ->from($table, 'class_id')
                ->where('class_name = ?', SurchargeTaxCalculator::NO_TAX_CLASS_NAME)
                ->where('class_type = ?', TaxClassManagementInterface::TYPE_PRODUCT)
        );

        if (!$exists) {
            $connection->insert($table, [
                'class_name' => SurchargeTaxCalculator::NO_TAX_CLASS_NAME,
                'class_type' => TaxClassManagementInterface::TYPE_PRODUCT,
            ]);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
