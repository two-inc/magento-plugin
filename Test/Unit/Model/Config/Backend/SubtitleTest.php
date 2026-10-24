<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\Subtitle;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;

/**
 * The tile emits the subtitle through AnchorOnlyHtmlEscaper, so copy that
 * escaper drops used to vanish at checkout with no merchant feedback. The save
 * refuses it instead.
 */
class SubtitleTest extends TestCase
{
    private function buildModel(string $value): Subtitle
    {
        return new Subtitle(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new AnchorOnlyHtmlEscaper(),
            null,
            null,
            ['value' => $value, 'scope' => 'default', 'scope_id' => 0]
        );
    }

    /**
     * Given copy the tile would not render as typed; When the section is
     * saved; Then the save is refused naming what would be shown instead.
     *
     * @dataProvider refusedSubtitles
     */
    public function testCopyTheTileWouldRewriteIsRefused(string $value, string $shown): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf(
            'Subtitle accepts plain text and a single link only; "%s" would be shown as "%s".',
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($shown, ENT_QUOTES, 'UTF-8')
        ));

        $this->buildModel($value)->beforeSave();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedSubtitles(): array
    {
        return [
            'dropped tag' => ['<b>Pay</b> later', 'Pay later'],
            'refused href' => ['<a href="javascript:alert(1)">go</a>', 'go'],
            'dropped attribute' => [
                '<a href="https://faq.example.test/x" onclick="steal()">read more</a>',
                '<a href="https://faq.example.test/x">read more</a>',
            ],
        ];
    }

    /**
     * @dataProvider acceptedSubtitles
     */
    public function testCopyTheTileRendersVerbatimIsStoredAsTyped(string $value): void
    {
        $model = $this->buildModel($value);
        $model->beforeSave();

        $this->assertSame($value, $model->getValue());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedSubtitles(): array
    {
        return [
            'cleared' => [''],
            'plain copy' => ['Pay later, interest free'],
            'apostrophe and ampersand' => ["Don't wait & save"],
            'single link' => ['<a href="https://faq.example.test/x">read more</a>'],
        ];
    }

    /**
     * The guard only runs where the form declares it, and the brand forms are a
     * second, generated save path for the same field.
     *
     * @dataProvider adminForms
     */
    public function testBothSubtitleFieldsDeclareThisGuard(string $relative): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/' . $relative);
        $fields = $xml->xpath('//field[@id="subtitle"]');

        $this->assertCount(1, $fields, $relative . ' should define the subtitle field once');
        $this->assertSame(
            'Two\\Gateway\\Model\\Config\\Backend\\Subtitle',
            (string)$fields[0]->backend_model,
            $relative . ' saves the subtitle without the guard'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminForms(): array
    {
        return [
            'two section' => ['etc/adminhtml/system.xml'],
            'brand forms' => ['etc/adminhtml/brand_form_template.xml'],
        ];
    }
}
