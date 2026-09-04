<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Save-time half of the TWO-25503 coupling: "Autofill company address"
 * (`enable_address_search`) can never be stored ON while "Enable company
 * search in address entry" (`enable_company_search`) is OFF.
 *
 * `enable_company_search` OFF does not disable company search — it just
 * relocates the control to the payment tile — but it does retire the
 * convenience the autofill setting exists for, so the stored value is
 * pinned off alongside it. `Repository::isAddressSearchEnabled()` applies
 * the same rule to the READ path (belt-and-suspenders, matching the
 * PrestaShop resolver: a row saved before this coupling existed, or written
 * by `config:set`/import, must not disagree with what the admin form shows).
 * The `<depends>` entry in system.xml only hides the field; it does not
 * touch the value a disabled/hidden control still posts, which is what this
 * class is for.
 */
class AddressSearchToggle extends Value
{
    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        if (!$this->isCompanySearchSubmittedOn()) {
            $this->setValue('0');
        }

        return parent::beforeSave();
    }

    /**
     * `enable_company_search` is a sibling field in the same group, so its
     * submitted value is on this model. A request that does not carry it at
     * all (e.g. a programmatic write of this field alone) has nothing to
     * force off against, so it is left untouched.
     */
    private function isCompanySearchSubmittedOn(): bool
    {
        $submitted = $this->getFieldsetDataValue('enable_company_search');

        if ($submitted === null || $submitted === false) {
            return true;
        }

        return (bool)(int)$submitted;
    }
}
