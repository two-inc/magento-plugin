<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;
use Two\Gateway\Model\Config\FieldGate\ConfiguredPredicateInterface;

/**
 * Hides each registered admin field unless its predicate accepts the effective value at the scope being edited.
 */
class HideFieldsUnlessConfigured
{
    /**
     * @param ConfiguredPredicateInterface[] $predicates Keyed `section_suffix/group/field`, as brand.xml suppressed_fields.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Loader $brands,
        private readonly array $predicates = []
    ) {
    }

    /**
     * @param bool $result Whatever Field::isVisible computed natively.
     */
    public function afterIsVisible(Field $subject, $result)
    {
        $predicate = $result ? $this->predicateFor($subject) : null;
        if ($predicate === null) {
            return $result;
        }
        $scope = $this->editedScope();
        if ($scope === null) {
            return false;
        }

        return $predicate->isConfigured(
            $this->scopeConfig->getValue($subject->getConfigPath() ?: $subject->getPath(), ...$scope)
        );
    }

    /** Sections are `<prefix>_<suffix>`: `two` for this module, a brand's section prefix for its synthesised form. */
    private function predicateFor(Field $field): ?ConfiguredPredicateInterface
    {
        $parts = explode('/', (string)$field->getPath());
        $section = $parts[0];
        $group = $parts[count($parts) - 2] ?? '';
        foreach ($this->predicates as $key => $predicate) {
            [$suffix, $keyGroup, $keyId] = array_pad(explode('/', (string)$key), 3, '');
            if ($keyGroup !== $group || $keyId !== $field->getId()) {
                continue;
            }
            foreach ($this->sectionPrefixes() as $prefix) {
                if ($section === $prefix . '_' . $suffix) {
                    return $predicate;
                }
            }
        }

        return null;
    }

    /** @return string[] */
    private function sectionPrefixes(): array
    {
        return array_merge(['two'], array_map(
            static fn (Descriptor $brand): string => $brand->getSectionPrefix(),
            array_values($this->brands->load())
        ));
    }

    /**
     * Scope the admin form is editing, from the same request params SurchargeTaxClass::resolveStoreId() reads;
     * null when the param names no store/website, which hides the field rather than trusting a wider scope.
     *
     * @return array{string, int|null}|null
     */
    private function editedScope(): ?array
    {
        try {
            $store = $this->request->getParam('store');
            if ($store) {
                return [ScopeInterface::SCOPE_STORE, (int)$this->storeManager->getStore($store)->getId()];
            }
            $website = $this->request->getParam('website');
            if ($website) {
                return [ScopeInterface::SCOPE_WEBSITE, (int)$this->storeManager->getWebsite($website)->getId()];
            }
        } catch (\Exception $e) {
            return null;
        }

        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];
    }
}
