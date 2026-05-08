<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;

use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Render module information html element in Stores Configuration
 */
class Header extends Field
{

    private const SIGN_UP_URL = 'https://portal.two.inc/auth/merchant/signup';

    private const DOCUMENTATION_URL = 'https://docs.two.inc/developer-portal/plugins/magento';

    /**
    * @var ConfigRepository
     */
    public $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/field/header.phtml';

    /**
     * @param Context $context
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Context $context,
        array $data = []
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element): string
    {
        $element->addClass('two');

        return $this->toHtml();
    }

    /**
     * Get Sign Up Url
     *
     * @return string
     */
    public function getSignUpUrl(): string
    {
        return self::SIGN_UP_URL;
    }

    /**
     * Get Documentation Url
     *
     * @return string
     */
    public function getDocumentationUrl(): string
    {
        return self::DOCUMENTATION_URL;
    }

    /**
     * Brand-bound product name for use in templates ($block->getProductName()).
     */
    public function getProductName(): string
    {
        return $this->brandRegistry->getProductName();
    }
}
