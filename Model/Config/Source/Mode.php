<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\EnvironmentMode;

/**
 * Environment options (TWO-26604): Production and Sandbox, plus Staging on
 * Two's infrastructure or when it is already stored at the scope being
 * edited, so saving the form never resets a stored staging.
 */
class Mode implements OptionSourceInterface
{
    /**
     * @var ConfigRepository
     */
    public $configRepository;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var AdminScope
     */
    private $adminScope;

    /**
     * @param ConfigRepository $configRepository
     * @param RequestInterface $request
     * @param AdminScope $adminScope
     */
    public function __construct(
        ConfigRepository $configRepository,
        RequestInterface $request,
        AdminScope $adminScope
    ) {
        $this->configRepository = $configRepository;
        $this->request = $request;
        $this->adminScope = $adminScope;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $labels = [
            'production' => __('Production'),
            'sandbox' => __('Sandbox'),
            'staging' => __('Staging'),
        ];
        // Read at the scope being edited, not through one of its stores.
        [$scopeId, $scope] = $this->adminScope->fromCodes(
            $this->request->getParam('store'),
            $this->request->getParam('website')
        );
        $options = [];
        foreach (EnvironmentMode::options($this->configRepository->getStoredMode($scopeId, $scope)) as $mode) {
            $options[] = ['value' => $mode, 'label' => $labels[$mode]];
        }
        return $options;
    }
}
