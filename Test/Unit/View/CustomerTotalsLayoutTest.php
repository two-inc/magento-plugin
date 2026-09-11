<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Magento's guest sales handles inherit nothing from their logged-in siblings —
 * each declares its own totals block — so a surface with no layout file of its
 * own renders the grand total with the surcharge folded in but no row naming it
 * (ABN-559).
 */
class CustomerTotalsLayoutTest extends TestCase
{
    /**
     * Every customer-facing sales surface, and the core totals block the
     * surcharge row attaches to.
     *
     * "Other charges" is reconciled on credit memos only, so only those
     * surfaces require its block.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public static function surfaceProvider(): array
    {
        $fee = ['Two\Gateway\Block\Sales\Total\Surcharge'];
        $memo = array_merge($fee, ['Two\Gateway\Block\Sales\Total\OtherCharges']);

        return [
            'order view' => ['sales_order_view', 'order_totals', $fee, 'the signed-in order view'],
            'guest order view' => ['sales_guest_view', 'order_totals', $fee, 'the guest order view'],
            'order print' => ['sales_order_print', 'order_totals', $fee, 'the signed-in order print page'],
            'guest order print' => ['sales_guest_print', 'order_totals', $fee, 'the guest order print page'],
            'invoice view' => ['sales_order_invoice', 'invoice_totals', $fee, 'the signed-in invoice view'],
            'guest invoice view' => ['sales_guest_invoice', 'invoice_totals', $fee, 'the guest invoice view'],
            'invoice print' => [
                'sales_order_printinvoice',
                'invoice_totals',
                $fee,
                'the signed-in invoice print page',
            ],
            'guest invoice print' => [
                'sales_guest_printinvoice',
                'invoice_totals',
                $fee,
                'the guest invoice print page',
            ],
            'creditmemo view' => [
                'sales_order_creditmemo',
                'creditmemo_totals',
                $memo,
                'the signed-in credit memo view',
            ],
            'guest creditmemo view' => [
                'sales_guest_creditmemo',
                'creditmemo_totals',
                $memo,
                'the guest credit memo view',
            ],
            'creditmemo print' => [
                'sales_order_printcreditmemo',
                'creditmemo_totals',
                $memo,
                'the signed-in credit memo print page',
            ],
            'guest creditmemo print' => [
                'sales_guest_printcreditmemo',
                'creditmemo_totals',
                $memo,
                'the guest credit memo print page',
            ],
        ];
    }

    /**
     * @param list<string> $requiredBlocks
     * @dataProvider surfaceProvider
     */
    public function testSurchargeRowIsDeclaredOnEveryCustomerFacingSurface(
        string $handle,
        string $container,
        array $requiredBlocks,
        string $description
    ): void {
        $path = $this->layoutDir() . '/' . $handle . '.xml';

        $this->assertFileExists(
            $path,
            sprintf(
                '%s omits the surcharge row: no view/frontend/layout/%s.xml.'
                . ' Guest and print handles inherit nothing from the signed-in handle.',
                $description,
                $handle
            )
        );

        $xml = (string) file_get_contents($path);

        $this->assertStringContainsString(
            sprintf('<referenceBlock name="%s">', $container),
            $xml,
            sprintf('%s attaches the surcharge row to a block other than %s.', $description, $container)
        );
        foreach ($requiredBlocks as $block) {
            $this->assertStringContainsString(
                $block,
                $xml,
                sprintf('%s does not declare %s.', $description, $block)
            );
        }
    }

    /**
     * `sales_order_invoice_view` is an adminhtml-only handle, so a frontend file
     * under that name reads as coverage while rendering nothing.
     */
    public function testNoFrontendLayoutUsesAnAdminOnlyHandleName(): void
    {
        $this->assertFileDoesNotExist(
            $this->layoutDir() . '/sales_order_invoice_view.xml',
            'sales_order_invoice_view is an adminhtml handle; the frontend invoice handle is sales_order_invoice.'
        );
    }

    private function layoutDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/view/frontend/layout';
        $this->assertDirectoryExists($dir, 'Cannot locate view/frontend/layout.');

        return $dir;
    }
}
