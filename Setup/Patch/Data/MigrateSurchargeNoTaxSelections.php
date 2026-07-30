<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\TaxClassManagementInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass as SurchargeTaxClassSource;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * Retire the plugin-provisioned "Payment Terms Surcharge - No Tax"
 * Product Tax Class as a surcharge tax treatment (TWO-25279).
 *
 * That class was inserted into the merchant's own `tax_class` table by
 * a former data patch (Setup\Patch\Data\SurchargeNoTaxClass, removed in
 * the same change as this patch), deliberately rule-free so that
 * selecting it guaranteed an untaxed surcharge. That is exactly what
 * the plugin must NOT offer: it is a never-taxed treatment the plugin
 * fabricated, not a tax rule the merchant configured in Magento, and it
 * appeared in the merchant's Tax Rules UI as if they had created it.
 *
 * This patch repoints every stored selection of that class at core's
 * "None" (Product Tax Class id 0), which is the same never-taxed
 * behaviour expressed in a platform-native value — so no merchant's
 * checkout tax changes as a result of running it. Core "None" is itself
 * suppressed from NEW selections by SurchargeTaxClassSource; migrated
 * scopes therefore hold a stored-but-hidden value, which that source
 * model re-injects for the scope that stores it. Without that
 * re-injection the select would render its placeholder, the next admin
 * save would post '' and the treatment guard would reject the whole
 * section save.
 *
 * Scope-agnostic by construction: it rewrites `core_config_data` rows
 * directly, matching `payment/<any brand code>/surcharge_tax_class` at
 * every scope (default / websites / stores), because brand overlays
 * save the same field under their own payment method code.
 *
 * The `tax_class` row itself is deliberately LEFT IN PLACE. Removing it
 * is not FK-blocked once no config points at it (core's only foreign
 * keys onto `tax_class.class_id` are `tax_calculation`'s two, and this
 * class ships rule-free), but a product's tax class is an EAV attribute
 * value with no FK at all — so a merchant who assigned the class to
 * real products would get silently orphaned attribute values rather
 * than an error. An orphan row that nothing references is cheaper than
 * that risk. It is logged so an operator can remove it by hand.
 */
class MigrateSurchargeNoTaxSelections implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var ReinitableConfigInterface
     */
    private $reinitableConfig;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        LogRepository $logRepository,
        ReinitableConfigInterface $reinitableConfig
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logRepository = $logRepository;
        $this->reinitableConfig = $reinitableConfig;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $taxClassTable = $this->moduleDataSetup->getTable('tax_class');

        $classId = $connection->fetchOne(
            $connection->select()
                ->from($taxClassTable, 'class_id')
                ->where('class_name = ?', SurchargeTaxCalculator::NO_TAX_CLASS_NAME)
                ->where('class_type = ?', TaxClassManagementInterface::TYPE_PRODUCT)
        );

        // Falsy covers both "no such row" (false) and the impossible
        // class_id 0 — core auto-increments from 1, and 0 is the
        // reserved "None" pseudo-id, never a real row.
        if (!$classId) {
            $this->moduleDataSetup->getConnection()->endSetup();
            return $this;
        }

        $configTable = $this->moduleDataSetup->getTable('core_config_data');
        $migratedRows = $connection->update(
            $configTable,
            ['value' => SurchargeTaxClassSource::NEVER_TAXED_CLASS_ID],
            [
                // The `_` in the key name are LIKE single-character
                // wildcards, so they are escaped: the pattern must mean the
                // literal config key, with `%` standing in only for the
                // brand's payment method code.
                'path LIKE ?' => 'payment/%/surcharge\_tax\_class',
                // Stored as varchar; bind the id as a string so the
                // comparison cannot depend on MySQL's numeric coercion.
                'value = ?' => (string)$classId,
            ]
        );

        $attachedRuleCount = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('tax_calculation'), 'COUNT(*)')
                ->where('product_tax_class_id = ?', $classId)
        );

        $this->logRepository->addDebugLog(
            'MigrateSurchargeNoTaxSelections: repointed ' . $migratedRows . ' surcharge tax '
            . 'treatment config row(s) from the plugin-provisioned "'
            . SurchargeTaxCalculator::NO_TAX_CLASS_NAME . '" Product Tax Class to core "None" '
            . '(identical never-taxed behaviour). The plugin no longer provisions or offers that '
            . 'class; its tax_class row is left in place and may be deleted by hand once no '
            . 'products are assigned to it.',
            [
                'tax_class_id' => (int)$classId,
                'migrated_config_rows' => $migratedRows,
                'attached_tax_rule_count' => $attachedRuleCount,
            ]
        );

        // The config cache still holds the pre-migration value, so anything
        // reading config in this same process — including the admin form, if
        // the patch is applied programmatically rather than through
        // `setup:upgrade` (which flushes on its own) — would keep serving the
        // old class id and mis-render the selector.
        $this->reinitableConfig->reinit();

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
