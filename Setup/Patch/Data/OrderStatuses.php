<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * OrderStatuses Data Patch
 */
class OrderStatuses implements DataPatchInterface
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ConfigRepository $configRepository
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $data = [];
        $statuses = [
            Two::STATUS_NEW => sprintf('%s New Order', $this->brandRegistry->getProvider()),
            Two::STATUS_FAILED => sprintf('%s Failed', $this->brandRegistry->getProvider()),
        ];

        foreach ($statuses as $code => $info) {
            if (!$this->isStatusAdded($code)) {
                $data[] = ['status' => $code, 'label' => $info];
            }
        }

        if ($data) {
            $this->moduleDataSetup->getConnection()->insertArray(
                $this->moduleDataSetup->getTable('sales_order_status'),
                ['status', 'label'],
                $data
            );
        }

        /**
         * Install order states from config
         */
        $data = [];
        $states = [
            'new' => [
                'statuses' => [Two::STATUS_NEW],
            ],
            'canceled' => [
                'statuses' => [Two::STATUS_FAILED],
            ],
        ];

        foreach ($states as $code => $info) {
            if (isset($info['statuses']) && !$this->isStateAdded($info['statuses'][0])) {
                foreach ($info['statuses'] as $status) {
                    $data[] = [
                        'status' => $status,
                        'state' => $code,
                        'is_default' => 0,
                        'visible_on_front' => 1,
                    ];
                }
            }
        }
        if ($data) {
            $this->moduleDataSetup->getConnection()->insertArray(
                $this->moduleDataSetup->getTable('sales_order_status_state'),
                ['status', 'state', 'is_default', 'visible_on_front'],
                $data
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        // Run AFTER MigrateAbnOrderStatuses so that, on an ABN merchant
        // upgrading from the legacy abn_payment fork, the migration
        // patch renames any existing `abn_*` rows in sales_order_status
        // to the canonical `two_*` codes BEFORE this patch's
        // isStatusAdded() guard checks. Without this dependency, on
        // patch-iteration ordering luck this patch could insert a
        // canonical row while a legacy row also exists, then the
        // migration's UPDATE would hit a PRIMARY KEY violation.
        return [MigrateAbnOrderStatuses::class];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @param $status
     * @return string
     */
    private function isStatusAdded($status)
    {
        $select = $this->moduleDataSetup->getConnection()->select()
            ->from($this->moduleDataSetup->getTable('sales_order_status'), 'status')
            ->where('status = :status');
        $bind = [':status' => $status];
        return $this->moduleDataSetup->getConnection()->fetchOne($select, $bind);
    }

    /**
     * @param $status
     * @return string
     */
    private function isStateAdded($status)
    {
        $select = $this->moduleDataSetup->getConnection()->select()
            ->from($this->moduleDataSetup->getTable('sales_order_status_state'), 'status')
            ->where('status = :status');
        $bind = [':status' => $status];
        return $this->moduleDataSetup->getConnection()->fetchOne($select, $bind);
    }
}
