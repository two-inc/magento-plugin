<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config;

use Magento\Framework\Exception\LocalizedException;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass as SurchargeTaxClassSource;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * Single decision point for "is this surcharge tax treatment a never-taxed
 * one?" (TWO-25279).
 *
 * Three places need that answer and they MUST agree exactly:
 *  - the option source, which omits such options from the dropdown;
 *  - the field's frontend model, which fails loud when a scope still holds
 *    one;
 *  - the field's backend model, which refuses to save one.
 *
 * A scope that is warned about but savable, or savable but not warned
 * about, would each be worse than either alone — so the rule lives here
 * once rather than being restated three times.
 *
 * Two shapes are never-taxed, and they are different kinds of thing:
 *  - core "None" is the fixed pseudo-id 0, matched numerically;
 *  - the "Payment Terms Surcharge - No Tax" class the plugin used to
 *    provision has a merchant-specific auto-increment id, so it can only
 *    be matched by resolving the id to its class name.
 */
class NeverTaxedTreatment
{
    /**
     * @var TaxClassRepositoryInterface
     */
    private $taxClassRepository;

    public function __construct(TaxClassRepositoryInterface $taxClassRepository)
    {
        $this->taxClassRepository = $taxClassRepository;
    }

    /**
     * Whether a stored/submitted surcharge tax treatment value leaves the
     * surcharge untaxed in every jurisdiction.
     *
     * Numeric comparison, not a string one, so '0', '0.0' and ' 0' are all
     * caught — an import or a hand-written config can produce any of them,
     * and Repository::getSurchargeTaxClassId() int-casts them all to 0.
     *
     * A value that does not resolve to a tax class is NOT never-taxed: that
     * is a different failure (SurchargeTaxCalculator logs it at checkout),
     * and reporting it here would put a misleading message on the field.
     * Any LocalizedException is swallowed rather than only
     * NoSuchEntityException, because one caller is a rendering path where
     * an unresolvable id must not blank the admin page.
     */
    public function isNeverTaxed(string $value): bool
    {
        if (trim($value) === '' || !is_numeric($value)) {
            return false;
        }
        $classId = (int)$value;
        if ($classId === (int)SurchargeTaxClassSource::NEVER_TAXED_CLASS_ID) {
            return true;
        }
        try {
            $taxClass = $this->taxClassRepository->get($classId);
        } catch (LocalizedException $e) {
            return false;
        }

        return $taxClass->getClassName() === SurchargeTaxCalculator::NO_TAX_CLASS_NAME;
    }
}
