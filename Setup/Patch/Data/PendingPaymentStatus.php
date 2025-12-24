<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use ABN\Gateway\Model\Two;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * PendingPaymentStatus Data Patch
 */
class PendingPaymentStatus implements DataPatchInterface
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

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
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->configRepository = $configRepository;
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        if ($this->isStatusAdded()) {
            $this->moduleDataSetup->getConnection()->endSetup();
            return $this;
        }

        $this->moduleDataSetup->getConnection()->insert(
            $this->moduleDataSetup->getTable('sales_order_status'),
            ['status' => Two::STATUS_PENDING, 'label' => sprintf('%s Pending', $this->configRepository::PROVIDER)]
        );

        $this->moduleDataSetup->getConnection()->insert(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            [
                'status' => Two::STATUS_PENDING,
                'state' => 'pending_payment',
                'is_default' => 1,
                'visible_on_front' => 0
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Check if status already added
     *
     * @return bool
     */
    private function isStatusAdded(): bool
    {
        $select = $this->moduleDataSetup->getConnection()->select()
            ->from($this->moduleDataSetup->getTable('sales_order_status'), 'status')
            ->where('status = :status');
        $bind = [':status' => Two::STATUS_PENDING];
        return (bool)$this->moduleDataSetup->getConnection()->fetchOne($select, $bind);
    }
}
