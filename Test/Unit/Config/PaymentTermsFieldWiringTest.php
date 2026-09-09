<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The payment-term validation and help text only run if the admin forms name
 * the models — and an overlay renders the brand template, not system.xml, so
 * an unwired field there loses the guard silently.
 */
class PaymentTermsFieldWiringTest extends TestCase
{
    private const FORMS = [
        'etc/adminhtml/system.xml' => 'two_payment',
        'etc/adminhtml/brand_form_template.xml' => '{{section_prefix}}_payment',
    ];

    /**
     * @dataProvider wiringProvider
     */
    public function testEveryAdminFormWiresTheModel(string $field, string $element, string $class, string $case): void
    {
        foreach (self::FORMS as $form => $section) {
            $path = dirname(__DIR__, 3) . '/' . $form;
            $xml = simplexml_load_file($path);
            $this->assertNotFalse($xml, sprintf('Cannot parse %s.', $form));

            $found = $xml->xpath(sprintf(
                '//section[@id="%s"]/group[@id="payment_terms"]/field[@id="%s"]/%s',
                $section,
                $field,
                $element
            ));

            $this->assertCount(1, $found, sprintf('%s in %s', $case, $form));
            $value = $element === 'comment' ? (string)$found[0]['model'] : trim((string)$found[0]);
            $this->assertSame($class, $value, sprintf('%s in %s', $case, $form));
        }
    }

    /**
     * The object manager leaves an optional argument at its default, so the
     * dropped-term log line is silent unless di.xml names the logger.
     */
    public function testDiXmlInjectsTheRepositoryLogger(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $this->assertNotFalse($xml, 'Cannot parse etc/di.xml.');

        $argument = $xml->xpath(
            '//type[@name="Two\Gateway\Model\Config\Repository"]/arguments/argument[@name="logger"]'
        );

        $this->assertCount(1, $argument);
        $this->assertSame('Psr\Log\LoggerInterface', trim((string)$argument[0]));
    }

    /**
     * The group's fields all bind through config_path, so at store and website scope every one of
     * them posts an inherit flag and reaches no backend model — this plugin is the only guard left.
     */
    public function testAdminhtmlDiXmlRegistersTheUnusableTermGuard(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/di.xml');
        $this->assertNotFalse($xml, 'Cannot parse etc/adminhtml/di.xml.');

        $plugin = $xml->xpath('//type[@name="Magento\Config\Model\Config"]/plugin');

        $this->assertCount(1, $plugin);
        $this->assertSame(
            'Two\Gateway\Plugin\Config\RefuseUnusableCustomTerm',
            (string)$plugin[0]['type']
        );
    }

    public static function wiringProvider(): array
    {
        return [
            [
                'payment_terms',
                'backend_model',
                'Two\Gateway\Model\Config\Backend\PaymentTermsCheckboxes',
                'the ticked terms are checked against the offered set on save',
            ],
            [
                'payment_terms_duration_days',
                'backend_model',
                'Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays',
                'the deprecated custom day can be removed but not changed on save',
            ],
            [
                'payment_terms_duration_days',
                'frontend_model',
                'Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCustomDays',
                'the deprecated custom day is rendered as keep-or-remove, not as free entry',
            ],
            [
                'payment_terms_duration_days',
                'comment',
                'Two\Gateway\Model\Config\Comment\PaymentTermsCustomDays',
                'the custom-day help text is rendered from the stored terms type',
            ],
            [
                'default_payment_term',
                'backend_model',
                'Two\Gateway\Model\Config\Backend\DefaultPaymentTerm',
                'the default is checked against the enabled terms on save',
            ],
        ];
    }
}
