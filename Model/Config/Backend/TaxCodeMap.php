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
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\TaxCodeSection;
use Two\Gateway\Model\Config\TaxCodeSectionResolver;

/**
 * Storage format for the tax codes of 0% lines (TWO-24877, TWO-26153): one
 * JSON object of row key => Two tax code. Each product tax class has these
 * rows:
 *
 * - `<class>|exempt`: a buyer in another EU country with a VAT number;
 * - `<class>|rate:<rate code>`: one per 0% tax rate the class's rules use,
 *   keyed by the rate's own code;
 * - `<class>|none`: no tax rule for the address.
 *
 * "(none)" is stored as absence, so an unconfigured merchant stores nothing.
 * The admin form posts the whole map as one JSON field (a rate code may hold
 * any character, and one field per row could run into max_input_vars). A
 * posted value that is not a JSON object is refused rather than saved as an
 * empty map, so a damaged post never deletes the merchant's rows.
 *
 * A save changes the stored map only while the section shows its rows
 * (TWO-26153): rows posted from a page whose section was hidden, whole or cut
 * short, change nothing and raise nothing, and where the merchant record or
 * the code list could not be read an edit is not saved and a notice says why.
 * A changed row must hold a code the list offers for 0% lines. "Clear saved
 * tax codes" posts CLEAR instead of the map and stores an empty map, whatever
 * the section shows.
 */
class TaxCodeMap extends Value
{
    /** What the "Clear saved tax codes" checkbox posts in place of the map. */
    public const CLEAR = '__clear__';

    private const KEY_PATTERN = '/^\d+\|(exempt|none|rate:.+)$/s';

    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /**
     * @var TaxCodeSectionResolver
     */
    private $sectionResolver;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        TaxCodeSectionResolver $sectionResolver,
        MessageManager $messageManager,
        BrandRegistryInterface $brandRegistry,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->sectionResolver = $sectionResolver;
        $this->messageManager = $messageManager;
        $this->brandRegistry = $brandRegistry;
    }

    public static function exemptKey(int $classId): string
    {
        return $classId . '|exempt';
    }

    public static function noRuleKey(int $classId): string
    {
        return $classId . '|none';
    }

    public static function rateKey(int $classId, string $rateCode): string
    {
        return $classId . '|rate:' . $rateCode;
    }

    /**
     * A stored value as the form writes it (TWO-26309): blank, or a JSON
     * object of valid rows. Null for anything else, which is never read as
     * "nothing mapped".
     *
     * @param mixed $value
     * @return array<string, string>|null
     */
    public static function read($value): ?array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [];
        }
        $decoded = is_string($value) ? json_decode($value) : null;
        if (!$decoded instanceof \stdClass) {
            return null;
        }
        $rows = (array)$decoded;
        $map = self::normalise($value);

        return count($map) === count($rows) ? $map : null;
    }

    /**
     * The usable entries of a stored or posted value. Also the read path, so a
     * value from `config:set` or an import passes the same rules.
     *
     * @param mixed $value JSON string or array
     * @return array<string, string> row key => tax code
     */
    public static function normalise($value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $code) {
            if (preg_match(self::KEY_PATTERN, (string)$key) && is_string($code)
                && preg_match(self::CODE_PATTERN, $code)
            ) {
                $map[(string)$key] = $code;
            }
        }
        ksort($map, SORT_NATURAL);

        return $map;
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if ($value === self::CLEAR) {
            // An empty object, not '': a store view's '' could read as no value and take its parent's map.
            $this->setValue('{}');

            return parent::beforeSave();
        }
        [$scopeId, $scope] = AdminScope::fromScope((string)$this->getScope(), $this->getScopeId());
        $current = self::read($this->getOldValue());
        $state = $this->sectionResolver->resolve($scopeId, $scope, $this->getOldValue());
        $readable = is_string($value) && (trim($value) === '' || json_decode($value) instanceof \stdClass);
        if ($state['view'] !== TaxCodeSection::VIEW_ROWS) {
            $this->_dataSaveAllowed = false;
            $error = TaxCodeSection::error($state, $this->brandRegistry->getProductName(), $state['country']);
            if ($error !== null && (!$readable || self::normalise($value) !== ($current ?? []))) {
                $this->messageManager->addWarningMessage(__(
                    'The tax codes for 0% lines were not saved, and your saved codes are unchanged: %1',
                    $error
                ));
            }

            return parent::beforeSave();
        }
        // Only the form's JSON object, or an emptied field, is saved: anything
        // else (a form rendered before an upgrade, a JSON list) would store an
        // empty map and delete every row.
        if (!$readable) {
            throw new LocalizedException(__(
                'The tax codes for 0% lines could not be read, so nothing was saved. Reload the page and try again.'
            ));
        }
        $map = self::normalise($value);
        $offered = array_column(TaxCodeSection::zeroRated($state['codes']), 'code');
        foreach ($map as $key => $code) {
            if (($current[$key] ?? null) !== $code && !in_array($code, $offered, true)) {
                $this->_dataSaveAllowed = false;
                $this->messageManager->addErrorMessage(__(
                    'The tax code %1 is not offered for 0% lines, so the tax codes were not saved and your saved codes are unchanged.',
                    $code
                ));

                return parent::beforeSave();
            }
        }
        $this->setValue($map === [] ? '' : (string)json_encode($map));

        return parent::beforeSave();
    }
}
