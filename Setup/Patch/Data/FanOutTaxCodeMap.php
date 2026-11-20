<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Two\Gateway\Model\Config\Backend\TaxCodeMap;
use Two\Gateway\Service\Order\ZeroTaxRates;

/**
 * Carries "Tax codes for 0% lines" from one code per product tax class to the
 * class's rows (TWO-26153): each old `class => code` entry becomes that
 * class's exempt-buyer row, its no-rule row and the row of every 0% tax rate
 * its rules use now, so every line the old mapping covered keeps its code.
 * Rates added later start on (none). A row already in the new shape is kept.
 *
 * Brand-agnostic (payment codes come from the rows actually present) and
 * scope-complete (each stored scope/scope_id row is rewritten in place).
 * Idempotent: a second run finds no old entries and writes nothing.
 */
class FanOutTaxCodeMap implements DataPatchInterface
{
    private const KEY = 'tax_code_map';

    private const OLD_KEY_PATTERN = '/^\d+$/';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var ZeroTaxRates
     */
    private $zeroTaxRates;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ZeroTaxRates $zeroTaxRates
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->zeroTaxRates = $zeroTaxRates;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $rates = null;
        $written = false;
        foreach ($this->storedRows() as $row) {
            $stored = json_decode((string)$row['value'], true);
            if (!is_array($stored)) {
                continue;
            }
            $old = array_filter($stored, static function ($code, $key) {
                return preg_match(self::OLD_KEY_PATTERN, (string)$key) === 1;
            }, ARRAY_FILTER_USE_BOTH);
            if ($old === []) {
                continue;
            }
            $rates = $rates ?? $this->zeroTaxRates->byClass();
            $rows = array_diff_key($stored, $old);
            foreach ($old as $classId => $code) {
                $classId = (int)$classId;
                $keys = [TaxCodeMap::exemptKey($classId), TaxCodeMap::noRuleKey($classId)];
                foreach ($rates[$classId] ?? [] as $rate) {
                    $keys[] = TaxCodeMap::rateKey($classId, $rate['code']);
                }
                foreach ($keys as $key) {
                    $rows[$key] = $rows[$key] ?? $code;
                }
            }
            $map = TaxCodeMap::normalise($rows);
            $this->configWriter->save(
                (string)$row['path'],
                $map === [] ? '' : (string)json_encode($map),
                (string)$row['scope'],
                (int)$row['scope_id']
            );
            $written = true;
        }

        if ($written) {
            $this->cacheTypeList->invalidate('config');
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', 'payment/%/' . self::KEY);

        return array_values(array_filter($connection->fetchAll($select), static function ($row) {
            // LIKE treats `_` as a wildcard, so re-check the shape exactly.
            $segments = explode('/', (string)$row['path']);

            return count($segments) === 3 && $segments[0] === 'payment' && $segments[2] === self::KEY;
        }));
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
