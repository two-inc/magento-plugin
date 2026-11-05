<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Two;

/**
 * TWO-27108: no English title is stored at install; an empty title shows the
 * translated default ("Business invoice", or the product name with a brand
 * overlay active), and a title the merchant set shows as set.
 */
class TwoTitleTest extends TestCase
{
    public static function titles(): array
    {
        return [
            // stored title, selected term, active brand code, shown, description
            'empty' => [null, 0, Two::CODE, 'Business invoice', 'an empty title shows the translated default'],
            'blank' => ['', 0, Two::CODE, 'Business invoice', 'a blank title is empty too'],
            'empty with a term' => [null, 30, Two::CODE, 'Business invoice - 30 days', 'the default carries the chosen term'],
            'set' => ['Pay by invoice', 0, Two::CODE, 'Pay by invoice', 'a merchant title shows as set'],
            'set with a term' => ['Pay by invoice', 60, Two::CODE, 'Pay by invoice - 60 days', 'a merchant title carries the chosen term'],
            'overlay, empty' => [null, 0, 'acme_payment', 'Acme Pay', 'with a brand overlay active, an empty title shows its product name'],
            'overlay, empty with a term' => [null, 30, 'acme_payment', 'Acme Pay - 30 days', 'the overlay product name carries the chosen term'],
            'overlay, set' => ['Pay by invoice', 0, 'acme_payment', 'Pay by invoice', 'an overlay shop\'s own title shows as set'],
        ];
    }

    #[DataProvider('titles')]
    public function testTheTitleFallsBackToTheTranslatedDefault(
        ?string $stored,
        int $term,
        string $brandCode,
        string $shown,
        string $description
    ): void {
        $reflection = new \ReflectionClass(TitleTestTwo::class);
        $model = $reflection->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Two::class, 'stubConfigData'))->setValue($model, ['title' => $stored]);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCode')->willReturn($brandCode);
        $brand->method('getProductName')->willReturn($brandCode === Two::CODE ? 'Two' : 'Acme Pay');
        (new \ReflectionProperty(Two::class, 'brandRegistry'))->setValue($model, $brand);
        $model->info = new class ($term) {
            private $term;

            public function __construct(int $term)
            {
                $this->term = $term;
            }

            public function getAdditionalInformation($key = null)
            {
                return $key === 'selectedTerm' ? $this->term : null;
            }
        };

        $this->assertSame($shown, (string)$model->getTitle(), $description);
    }

    public function testNoEnglishTitleIsShippedAsTheDefault(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');

        $this->assertSame('', trim((string)$config->default->payment->two_payment->title));
    }
}

/** The order's payment info, which the payment method reads the chosen term from. */
class TitleTestTwo extends Two
{
    /** @var object */
    public $info;

    public function getInfoInstance()
    {
        return $this->info;
    }
}
