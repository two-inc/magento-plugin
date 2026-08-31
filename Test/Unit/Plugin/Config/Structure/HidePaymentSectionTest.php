<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Plugin\Config\Structure\HidePaymentSection;

class HidePaymentSectionTest extends TestCase
{
    private const NEW_PATH = 'two_brand_synthesis/hide_payment_section/enabled';
    private const LEGACY_PATH = 'payment/two_payment/hide_when_overlay_installed';

    /**
     * @param bool                      $overlayInstalled
     * @param bool|null                 $hideFlag Convenience: value stored at the NEW path.
     *                                            null = unset (nothing stored anywhere).
     * @param array<string,string|null> $stored   Explicit path => stored value map, overrides
     *                                            $hideFlag. Any path absent from the map reads
     *                                            back as null, i.e. "never set".
     */
    private function plugin(
        bool $overlayInstalled,
        ?bool $hideFlag = null,
        array $stored = []
    ): HidePaymentSection {
        $registry = $this->createMock(BrandOverlayRegistryInterface::class);
        $registry->method('isOverlayInstalled')->willReturn($overlayInstalled);

        if ($stored === [] && $hideFlag !== null) {
            $stored = [self::NEW_PATH => $hideFlag ? '1' : '0'];
        }

        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')
            ->willReturnCallback(static fn ($path) => $stored[$path] ?? null);

        return new HidePaymentSection($registry, $scope);
    }

    /**
     * Build an anonymous subclass of Section that overrides getId(). We can't
     * use createMock(Section::class) because getId comes from
     * Magento\Framework\Data\Structure\Element's __call magic in the CI test
     * stub (no declared method, PHPUnit throws MethodCannotBeConfiguredException),
     * and we can't use ReflectionClass::newInstanceWithoutConstructor + setData
     * because the test-bundled Section stub doesn't extend DataObject and so
     * has no setData. Skipping the parent constructor keeps us independent of
     * its required arguments.
     */
    private function section(string $id): Section
    {
        return new class($id) extends Section {
            // phpcs:disable
            public function __construct(private string $sectionId)
            {
                // skip parent constructor — only getId() is exercised by the plugin
            }
            public function getId()
            {
                return $this->sectionId;
            }
            // phpcs:enable
        };
    }

    public function testPassThroughWhenSectionAlreadyHidden(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), false));
    }

    public function testPassThroughForUnrelatedSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertTrue($plugin->afterIsVisible($this->section('catalog'), true));
    }

    public function testPassThroughWhenNoOverlayInstalled(): void
    {
        $plugin = $this->plugin(false, true);
        $this->assertTrue($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testPassThroughWhenHideFlagDisabled(): void
    {
        $plugin = $this->plugin(true, false);
        $this->assertTrue($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testHidesTwoPaymentSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testHidesTwoGeneralSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_general'), true));
    }

    public function testHidesTwoCheckoutFieldsSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_checkout_fields'), true));
    }

    public function testHidesTwoOrderManagementSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_order_management'), true));
    }

    public function testHidesTwoVersionSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_version'), true));
    }

    // --- TWO-25191: flag moved to two_brand_synthesis/, legacy path still read ---

    public function testNewPathSetToZeroShows(): void
    {
        $plugin = $this->plugin(true, null, [self::NEW_PATH => '0']);
        $this->assertTrue($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testLegacyPathAloneIsHonoured(): void
    {
        $plugin = $this->plugin(true, null, [self::LEGACY_PATH => '0']);
        $this->assertTrue(
            $plugin->afterIsVisible($this->section('two_payment'), true),
            'A merchant who ran the pre-TWO-25191 `config:set ... 0` must keep their opt-out.'
        );
    }

    public function testLegacyPathSetToOneHides(): void
    {
        $plugin = $this->plugin(true, null, [self::LEGACY_PATH => '1']);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testNeitherPathSetDefaultsToHide(): void
    {
        $plugin = $this->plugin(true, null, []);
        $this->assertFalse(
            $plugin->afterIsVisible($this->section('two_payment'), true),
            'Default is 1 (hide) — there is no etc/config.xml default, so the '
            . 'code-side DEFAULT_HIDE must supply it.'
        );
    }

    public function testNewPathZeroBeatsLegacyOne(): void
    {
        $plugin = $this->plugin(true, null, [
            self::NEW_PATH => '0',
            self::LEGACY_PATH => '1',
        ]);
        $this->assertTrue(
            $plugin->afterIsVisible($this->section('two_payment'), true),
            'The new path wins whenever it is set — the legacy read is a fallback only.'
        );
    }

    public function testNewPathOneBeatsLegacyZero(): void
    {
        $plugin = $this->plugin(true, null, [
            self::NEW_PATH => '1',
            self::LEGACY_PATH => '0',
        ]);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testEmptyStringTreatedAsUnset(): void
    {
        $plugin = $this->plugin(true, null, [
            self::NEW_PATH => '',
            self::LEGACY_PATH => '0',
        ]);
        $this->assertTrue(
            $plugin->afterIsVisible($this->section('two_payment'), true),
            'An empty stored value is not a deliberate 0; it must fall through to the legacy path.'
        );
    }

    public function testConfigXmlDeclaresNoDefaultForEitherPath(): void
    {
        $configXml = dirname(__DIR__, 5) . '/etc/config.xml';
        $this->assertFileExists($configXml);

        $xml = simplexml_load_file($configXml);
        $this->assertNotFalse($xml, 'etc/config.xml must parse');

        $this->assertEmpty(
            $xml->xpath('/config/default/payment/two_payment/hide_when_overlay_installed'),
            'The legacy default was removed by TWO-25191; re-adding it would make the '
            . 'legacy path never read back as null and pin every merchant to 1.'
        );
        $this->assertEmpty(
            $xml->xpath('/config/default/two_brand_synthesis/hide_payment_section'),
            'Declaring a default for the new path would make it never read back as null '
            . 'and silently disable the legacy-path fallback in HidePaymentSection.'
        );
    }
}
