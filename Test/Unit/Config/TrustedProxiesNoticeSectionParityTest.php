<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\AdminNotification\TrustedProxiesMessage;

/**
 * The notice's "Set Trusted proxies" link is a section id assembled from the
 * active brand's prefix, and nothing at runtime tells that id from a real one —
 * a renamed section leaves the link 404ing on every install.
 */
class TrustedProxiesNoticeSectionParityTest extends TestCase
{
    /**
     * @dataProvider adminForms
     */
    public function testTheNoticeLinksASectionTheAdminFormDeclares(
        string $file,
        string $sectionPrefix,
        string $description
    ): void {
        $route = (string)(new \ReflectionClass(TrustedProxiesMessage::class))->getConstant('SETTINGS_ROUTE');
        $this->assertStringContainsString('/section/', $route, 'the notice no longer links a config section');

        $xml = simplexml_load_file(__DIR__ . '/../../../' . $file);
        $this->assertNotFalse($xml, sprintf('Cannot parse %s.', $file));

        $this->assertCount(
            1,
            $xml->xpath(sprintf('//section[@id="%s"]', sprintf(substr(strrchr($route, '/'), 1), $sectionPrefix))) ?: [],
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function adminForms(): array
    {
        return [
            'vanilla' => [
                'etc/adminhtml/system.xml',
                'two',
                'the unbranded install links its own static section',
            ],
            'brand template' => [
                'etc/adminhtml/brand_form_template.xml',
                '{{section_prefix}}',
                'an overlay renders only synthesised sections, so the template is what the link resolves against',
            ],
        ];
    }
}
