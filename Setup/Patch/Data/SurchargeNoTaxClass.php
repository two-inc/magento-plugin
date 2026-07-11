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
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
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
 * Name-collision guard: if a merchant already has a Product Tax Class
 * with this exact name, the patch does NOT insert a duplicate — but a
 * pre-existing class may carry Tax Rules, silently breaking the
 * "guaranteed untaxed" promise. In that case the patch logs a loud
 * error so the collision is visible; it never silently treats a
 * rule-bearing class as the safe no-tax class. (Runtime defence in
 * depth: SurchargeTaxCalculator also logs an error if this class ever
 * resolves non-zero tax at checkout.)
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

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        LogRepository $logRepository
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logRepository = $logRepository;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('tax_class');

        $existingClassId = $connection->fetchOne(
            $connection->select()
                ->from($table, 'class_id')
                ->where('class_name = ?', SurchargeTaxCalculator::NO_TAX_CLASS_NAME)
                ->where('class_type = ?', TaxClassManagementInterface::TYPE_PRODUCT)
        );

        if ($existingClassId) {
            // Name collision (or idempotent re-run of this patch). Safe
            // only if the existing class has NO Tax Rules attached —
            // rules are recorded in tax_calculation.product_tax_class_id.
            $attachedRuleCount = (int)$connection->fetchOne(
                $connection->select()
                    ->from($this->moduleDataSetup->getTable('tax_calculation'), 'COUNT(*)')
                    ->where('product_tax_class_id = ?', $existingClassId)
            );
            if ($attachedRuleCount > 0) {
                $this->logRepository->addErrorLog(
                    'SurchargeNoTaxClass: a Product Tax Class named "'
                    . SurchargeTaxCalculator::NO_TAX_CLASS_NAME . '" already exists and has '
                    . $attachedRuleCount . ' Tax Rule(s) attached. It CANNOT guarantee an untaxed '
                    . 'surcharge — do not select it as the Surcharge Tax Class expecting zero tax, '
                    . 'or detach its Tax Rules first. No replacement class was created.',
                    ['class_id' => $existingClassId, 'attached_rule_count' => $attachedRuleCount]
                );
            }
        } else {
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
