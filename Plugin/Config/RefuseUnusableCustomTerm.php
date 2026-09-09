<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config;

use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Refuses a section save that would leave an unusable custom term in effect at the scope saved.
 *
 * Every field in this group binds through `config_path`, which the admin form's config-data filter
 * does not match, so each renders with `inherit` ticked at store and website scope — and an
 * inherit-flagged field is routed to the delete transaction, where no backend model's beforeSave
 * runs. This is the only guard those scopes reach (ABN-522).
 */
class RefuseUnusableCustomTerm
{
    private const GROUP = 'payment_terms';

    private const FIELD = 'payment_terms_duration_days';

    public function __construct(
        private readonly Structure $structure,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SettingChecker $settingChecker,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws LocalizedException when the value the saved scope inherits is not a number of days.
     */
    public function beforeSave(Config $subject): void
    {
        $groups = $subject->getGroups();
        $posted = is_array($groups) ? ($groups[self::GROUP]['fields'][self::FIELD] ?? null) : null;
        // A value posted for writing reaches the backend model, which refuses an unusable one there.
        if (!is_array($posted) || empty($posted['inherit'])) {
            return;
        }

        $field = $this->field((string)$subject->getSection());
        $scope = $this->scope($subject);
        if ($field === null || $scope === null) {
            return;
        }

        // Locked in env.php, so no answer the merchant gives removes it and refusing would deadlock.
        if ($this->settingChecker->isReadOnly($field->getPath(), $scope['type'], $scope['code'])) {
            return;
        }

        $inherited = $this->scopeConfig->getValue(
            (string)$field->getConfigPath(),
            $scope['parentType'],
            $scope['parentId']
        );
        if (!StoredTerm::isUnusable($inherited)) {
            return;
        }

        throw new LocalizedException(__(
            'Custom payment terms (days) holds "%1", which is not a usable number of days: untick the'
            . ' inherit box on that field and choose Remove to clear it here, or choose Remove at the'
            . ' scope it is set on.',
            trim((string)$inherited)
        ));
    }

    /** Null for any section that does not declare the field, an overlay's own sections included. */
    private function field(string $section): ?Field
    {
        if ($section === '') {
            return null;
        }
        $element = $this->structure->getElement($section . '/' . self::GROUP . '/' . self::FIELD);

        // An undeclared path resolves to an empty element, which carries no config path.
        return $element instanceof Field && (string)$element->getConfigPath() !== '' ? $element : null;
    }

    /**
     * The scope saved and the wider one it inherits from. Read from the model rather than the
     * request because the save pipeline scopes its writes from these same two values.
     *
     * @return array{type: string, code: string|null, parentType: string, parentId: int|null}|null
     */
    private function scope(Config $subject): ?array
    {
        try {
            $store = (string)$subject->getStore();
            if ($store !== '') {
                $resolved = $this->storeManager->getStore($store);

                return [
                    'type' => 'stores',
                    'code' => (string)$resolved->getCode(),
                    'parentType' => ScopeInterface::SCOPE_WEBSITE,
                    'parentId' => (int)$resolved->getWebsiteId(),
                ];
            }
            $website = (string)$subject->getWebsite();
            if ($website !== '') {
                return [
                    'type' => 'websites',
                    'code' => (string)$this->storeManager->getWebsite($website)->getCode(),
                    'parentType' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                    'parentId' => null,
                ];
            }
        } catch (\Exception $e) {
            return null;
        }

        // Default scope offers no inherit box, so a flag posted there names no wider scope to read.
        return null;
    }
}
