<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ProcessGuard\Test\Unit\Plugin\Catalog;

use Commerce\ProcessGuard\Plugin\Catalog\GuardedProductSave;
use Commerce\ProcessGuard\Test\Support\RecordingGuard;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The plugin is transparent: same return, same arguments, exceptions untouched.
 */
class GuardedProductSaveTest extends TestCase
{
    public function testTheSavedProductIsReturnedUnchanged(): void
    {
        $product = $this->product('SKU-1');
        $saved = $this->product('SKU-1-SAVED');

        $result = $this->plugin()->aroundSave(
            $this->repository(),
            static fn (): ProductInterface => $saved,
            $product
        );

        $this->assertSame($saved, $result);
    }

    /**
     * `$saveOptions` decides whether custom options are persisted.
     */
    public function testTheProductAndSaveOptionsFlagAreForwarded(): void
    {
        $product = $this->product('SKU-1');
        $seen = [];

        $this->plugin()->aroundSave(
            $this->repository(),
            static function (ProductInterface $passed, bool $saveOptions) use (&$seen, $product): ProductInterface {
                $seen = ['product' => $passed, 'saveOptions' => $saveOptions];

                return $product;
            },
            $product,
            true
        );

        $this->assertSame($product, $seen['product']);
        $this->assertTrue($seen['saveOptions']);
    }

    public function testSaveOptionsDefaultsToFalseAsTheRepositoryDoes(): void
    {
        $seen = null;

        $this->plugin()->aroundSave(
            $this->repository(),
            static function (ProductInterface $passed, bool $saveOptions) use (&$seen): ProductInterface {
                $seen = $saveOptions;

                return $passed;
            },
            $this->product('SKU-1')
        );

        $this->assertFalse($seen);
    }

    public function testTheSaveIsRunAsANamedProcess(): void
    {
        $guard = new RecordingGuard();

        $this->plugin($guard)->aroundSave(
            $this->repository(),
            fn (): ProductInterface => $this->product('SKU-1'),
            $this->product('SKU-1')
        );

        $this->assertSame(GuardedProductSave::PROCESS, $guard->process());
    }

    /**
     * The SKU is what makes the report actionable: "product saves cost 40
     * minutes" is a fact, and "these three SKUs cost 40 minutes" is a lead.
     */
    public function testTheSkuIsRecordedAsContext(): void
    {
        $guard = new RecordingGuard();

        $this->plugin($guard)->aroundSave(
            $this->repository(),
            fn (): ProductInterface => $this->product('SKU-1'),
            $this->product('SKU-1')
        );

        $this->assertSame('SKU-1', $guard->context['sku']);
        $this->assertSame(GuardedProductSave::PROCESS, $guard->context['label']);
    }

    /**
     * A product with no SKU yet — a new one mid-construction — must not take
     * the save down with a type error from the monitoring wrapper.
     */
    public function testAProductWithNoSkuIsRecordedAsAnEmptyString(): void
    {
        $guard = new RecordingGuard();
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn(null);

        $this->plugin($guard)->aroundSave(
            $this->repository(),
            static fn (): ProductInterface => $product,
            $product
        );

        $this->assertSame('', $guard->context['sku']);
    }

    /**
     * The rule the whole module turns on: a process wrapper never swallows.
     */
    public function testAFailingSavePropagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the row is locked');

        $this->plugin()->aroundSave(
            $this->repository(),
            static function (): ProductInterface {
                throw new RuntimeException('the row is locked');
            },
            $this->product('SKU-1')
        );
    }

    private function plugin(?RecordingGuard $guard = null): GuardedProductSave
    {
        return new GuardedProductSave($guard ?? new RecordingGuard());
    }

    private function repository(): ProductRepositoryInterface
    {
        return $this->createMock(ProductRepositoryInterface::class);
    }

    private function product(string $sku): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
