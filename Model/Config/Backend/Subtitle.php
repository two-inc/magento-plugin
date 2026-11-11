<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;

/**
 * Entry gate for the checkout tile subtitle, on both the Two section and the
 * generated brand forms - they are one field mechanism with two config paths.
 *
 * The subtitle is plain text (TWO-27108): the tile shows it escaped with any
 * markup removed, so markup typed here is refused rather than disappearing at
 * checkout with no merchant feedback. A value saved before, posted back
 * unchanged, is not refused: the tile already shows it as plain text, and
 * refusing it would fail every later save of the section.
 */
class Subtitle extends Value
{
    /**
     * @var AnchorOnlyHtmlEscaper
     */
    private $escaper;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        AnchorOnlyHtmlEscaper $escaper,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->escaper = $escaper;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (string)$this->getValue();
        $shown = strip_tags($value);
        if ($shown !== $value && $value !== (string)$this->getOldValue()) {
            // Admin notices render their text unescaped, and the whole point
            // of this message is to show markup as markup.
            throw new LocalizedException(__(
                'Subtitle accepts plain text only; "%1" would be shown as "%2".',
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($shown, ENT_QUOTES, 'UTF-8')
            ));
        }
        return parent::beforeSave();
    }
}
