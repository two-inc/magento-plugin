<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Mode Options
 */
class Mode implements OptionSourceInterface
{
    /**
     * @var ConfigRepository
     */
    public $configRepository;

    /**
     * @param ConfigRepository $configRepository
     */
    public function __construct(
        ConfigRepository $configRepository
    ) {
        $this->configRepository = $configRepository;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $modes = [
            "production" => 1,
            "sandbox" => 1,
        ];
        $mode = $this->configRepository->getMode();
        if ($mode) {
            $modes[$mode] = 1;
        }
        $options = [];
        foreach ($modes as $mode => $value) {
            $options[] = [
                'value' => $mode,
                'label' => __(ucfirst($mode)),
            ];
        }
        return $options;
    }
}
