<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Express;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Controller\Express\Confirm;

/**
 * TWO-25800: the express handoff is decided from the QUOTE, never inferred from
 * the add-to-cart response.
 *
 * The response cannot answer it — Magento's widget fires its event from the
 * success callback whatever the body says, and the controller ends both its
 * success and its failure paths at `goBack()`, which emits only `backUrl`. So
 * the record of what happened is the basket, and that is what these assert.
 *
 * The case that matters most is the middle one: core applies our return URL on
 * the generic exception path too, having just cleared its own error message, so
 * without this the buyer reached checkout with the item missing and nothing to
 * tell them why.
 */
class ConfirmTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $redirects = [];

    private array $errors = [];

    /** The session's completion set, as the controller leaves it. */
    private $stamps = null;

    private function controller(
        array $quoteProductIds,
        ?string $type = 'simple',
        $stamped = null,
        ?RequestInterface $request = null
    ): Confirm {
        if ($request === null) {
            $request = $this->createMock(RequestInterface::class);
            $request->method('getParam')->willReturnCallback(
                static function ($name) {
                    return $name === Confirm::TOKEN_PARAM ? 'tok-abc' : '42';
                }
            );
        }

        $items = [];
        foreach ($quoteProductIds as $id) {
            $item = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
                ->addMethods(['getProductId'])
                ->getMock();
            $item->method('getProductId')->willReturn($id);
            $items[] = $item;
        }

        // The set of completions Observer\ExpressAddSucceeded leaves when core
        // reports an add complete, keyed by the attempt's own token. THIS
        // attempt's entry is consumed on read so it cannot confirm twice; the
        // rest belong to other tabs and must survive.
        $this->stamps = is_array($stamped) ? $stamped : ($stamped === null ? null : [$stamped => true]);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getData')->willReturnCallback(fn () => $this->stamps);
        $session->method('setData')->willReturnCallback(function ($key, $value) {
            $this->stamps = $value;
        });

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        if ($type === null) {
            $productRepository->method('getById')->willThrowException(new \Exception('gone'));
        } else {
            $product = $this->createMock(Product::class);
            $product->method('getTypeId')->willReturn($type);
            $product->method('getProductUrl')->willReturn('https://shop.test/hoodie.html');
            $productRepository->method('getById')->willReturn($product);
        }

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirects[] = [$path, $params];

            return $redirect;
        });
        $redirect->method('setUrl')->willReturnCallback(function ($url) use ($redirect) {
            $this->redirects[] = [$url, []];

            return $redirect;
        });

        $factory = $this->createMock(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        $messages = $this->createMock(MessageManager::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->errors[] = (string)$m;

            return $messages;
        });

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCode')->willReturn('two_payment');

        return new Confirm(
            $request,
            $factory,
            $session,
            $productRepository,
            $messages,
            $this->createMock(LogRepository::class),
            $brand
        );
    }

    protected function setUp(): void
    {
        $this->redirects = [];
        $this->errors = [];
        $this->stamps = null;
    }

    /** The item is in the basket, so the buyer goes on with the marker. */
    public function testAConfirmedAddReachesCheckoutCarryingTheMarker(): void
    {
        $this->controller([42], 'simple', 'tok-abc')->execute();

        $this->assertSame('checkout', $this->redirects[0][0]);
        $this->assertSame(['_query' => ['two_express' => 'tok-abc']], $this->redirects[0][1]);
        $this->assertSame([], $this->errors);
    }

    /**
     * The whole reason this controller exists. Core applies our return URL on
     * its generic failure path too, after clearing its own message, so an
     * unconfirmed add must be turned back rather than handed onward.
     */
    public function testAnUnconfirmedAddGoesBackToTheProductWithAReason(): void
    {
        $this->controller([99], 'simple', null)->execute();

        $this->assertSame('https://shop.test/hoodie.html', $this->redirects[0][0]);
        $this->assertCount(1, $this->errors);
        $this->assertNotSame('', $this->errors[0]);
    }

    /**
     * A grouped product's own id never reaches the quote, so nothing about it
     * can be compared. Confirmation identifies the ATTEMPT instead, which
     * leaves no product-shaped question for any product type to answer.
     */
    public function testAGroupedProductNeedsNoSpecialCase(): void
    {
        $this->controller([7, 8], 'grouped', 'tok-abc')->execute();

        $this->assertSame('checkout', $this->redirects[0][0]);
        $this->assertSame([], $this->errors);
    }

    /**
     * A product that no longer loads is the `!$product` case core answers with
     * a bare goBack(). Nothing was added, so the buyer is turned back — to the
     * cart, since there is no product page left to return them to.
     */
    public function testAProductThatWillNotLoadFallsBackToTheCart(): void
    {
        $this->controller([], null, null)->execute();

        $this->assertSame('checkout/cart', $this->redirects[0][0]);
        $this->assertCount(1, $this->errors);
    }

    /**
     * A buyer who already had this product, and whose add then failed, leaves
     * no stamp — so they are turned back rather than confirmed on the strength
     * of a basket that already contained it.
     */
    public function testAFailedAddIsCaughtEvenWhenTheBuyerAlreadyHadThatProduct(): void
    {
        $this->controller([42], 'simple', null)->execute();

        $this->assertSame('https://shop.test/hoodie.html', $this->redirects[0][0]);
        $this->assertCount(1, $this->errors);
    }

    /** The attempt stamped the session, so it did what it said. */
    public function testAStampedAttemptIsConfirmed(): void
    {
        $this->controller([42], 'simple', 'tok-abc')->execute();

        $this->assertSame('checkout', $this->redirects[0][0]);
        $this->assertSame([], $this->errors);
    }

    /**
     * A completion from some other add — another tab, an ordinary Add to Cart
     * that raced this one — carries a different token and confirms nothing.
     */
    public function testAStampFromAnotherAttemptConfirmsNothing(): void
    {
        $this->controller([42], 'simple', 'tok-other')->execute();

        $this->assertSame('https://shop.test/hoodie.html', $this->redirects[0][0]);
        $this->assertCount(1, $this->errors);
    }

    /**
     * A session is not one tab. Two express adds can complete before either is
     * confirmed, so confirming one must leave the other's completion standing:
     * destroying it turns a buyer back whose item IS in the basket.
     */
    public function testConfirmingOneAttemptLeavesAnotherTabsCompletionStanding(): void
    {
        $this->controller([42], 'simple', ['tok-other' => true, 'tok-abc' => true])->execute();

        $this->assertSame('checkout', $this->redirects[0][0]);
        $this->assertSame([], $this->errors);
        $this->assertSame(['tok-other' => true], $this->stamps);
    }

    /**
     * And the other way round: an arrival that finds nothing of its own writes
     * nothing back, so a completion waiting for its own tab is untouched.
     */
    public function testAnUnmatchedConfirmationDestroysNoOtherCompletion(): void
    {
        $this->controller([42], 'simple', ['tok-other' => true])->execute();

        $this->assertSame('https://shop.test/hoodie.html', $this->redirects[0][0]);
        $this->assertSame(['tok-other' => true], $this->stamps);
    }

    /** One completion, one confirmation: a replayed URL confirms nothing. */
    public function testACompletionIsConsumedExactlyOnce(): void
    {
        $controller = $this->controller([42], 'simple', ['tok-abc' => true]);

        $controller->execute();
        $this->assertSame('checkout', $this->redirects[0][0]);
        $this->assertNull($this->stamps);

        $controller->execute();
        $this->assertSame('https://shop.test/hoodie.html', $this->redirects[1][0]);
    }

    /**
     * A token of digits alone is stored by PHP as an int key; it still has to
     * match the string the buyer arrives with.
     */
    public function testANumericTokenStillMatches(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($name) => $name === Confirm::TOKEN_PARAM ? '12345' : '42'
        );

        $this->stamps = ['12345' => true];
        $this->assertArrayHasKey(12345, $this->stamps, 'PHP stored it as an int, which is the case under test');

        $this->controller([42], 'simple', ['12345' => true], $request)->execute();

        $this->assertSame('checkout', $this->redirects[0][0]);
    }
}
