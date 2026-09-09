<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config;

use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Loader;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Refuses a section save that would leave an unusable custom term in effect at the scope saved, in
 * the one shape the field's own backend model never sees: a scope holding no row of its own renders
 * the row inherited and disabled, so it posts its inherit flag and no value. What is refused over
 * there is the value the page displayed (ABN-522).
 */
class RefuseUnusableCustomTerm
{
    private const GROUP = 'payment_terms';

    private const FIELD = 'payment_terms_duration_days';

    public function __construct(
        private readonly Structure $structure,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SettingChecker $settingChecker,
        private readonly StoreManagerInterface $storeManager,
        private readonly Loader $configLoader
    ) {
    }

    /**
     * @throws LocalizedException when the scope saved shows an unusable value it holds no row for.
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
        $path = (string)$field->getConfigPath();

        // Locked in env.php, so no answer the merchant gives removes it and refusing would deadlock.
        if ($this->settingChecker->isReadOnly($path, $scope['type'], $scope['code'])) {
            return;
        }

        // A row of its own is the value on the page, so refusing over the wider scope's instead
        // would gate the save on a string the merchant cannot see or reach from here.
        if ($this->holdsItsOwnRow($field, $scope)) {
            return;
        }

        $shown = $this->scopeConfig->getValue($path, $scope['type'], $scope['code']);
        if (!StoredTerm::isUnusable($shown)) {
            return;
        }

        throw new LocalizedException(__(
            'Custom payment terms (days) holds "%1", which is not a usable number of days: untick the'
            . ' inherit box on that field and choose Remove to clear it here, or choose Remove at the'
            . ' scope it is set on.',
            trim((string)$shown)
        ));
    }

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
     * @param array{type: string, code: string, id: int} $scope
     */
    private function holdsItsOwnRow(Field $field, array $scope): bool
    {
        // The loader filters on a path prefix, so the group is the narrowest query returning the row.
        $rows = $this->configLoader->getConfigByPath(
            (string)$field->getGroupPath(),
            $scope['type'],
            $scope['id'],
            false
        );

        return array_key_exists((string)$field->getConfigPath(), $rows);
    }

    /**
     * The scope saved, typed as the save pipeline, env.php and the config reader all name it.
     *
     * @return array{type: string, code: string, id: int}|null
     */
    private function scope(Config $subject): ?array
    {
        try {
            $store = (string)$subject->getStore();
            if ($store !== '') {
                $resolved = $this->storeManager->getStore($store);

                return ['type' => 'stores', 'code' => (string)$resolved->getCode(), 'id' => (int)$resolved->getId()];
            }
            $website = (string)$subject->getWebsite();
            if ($website !== '') {
                $resolved = $this->storeManager->getWebsite($website);

                return ['type' => 'websites', 'code' => (string)$resolved->getCode(), 'id' => (int)$resolved->getId()];
            }
        } catch (\Exception $e) {
            return null;
        }

        // The inherit box default scope offers restores the module default, which is blank, so a
        // tick there removes the value rather than adopting another.
        return null;
    }
}
