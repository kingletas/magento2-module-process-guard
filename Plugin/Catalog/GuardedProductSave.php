<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Plugin\Catalog;

use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Times a product save, which is where the admin and the importer meet.
 */
class GuardedProductSave
{
    public const PROCESS = 'catalog.product_save';

    public function __construct(
        private readonly ProcessGuardInterface $guard
    ) {
    }

    /**
     * @param bool $saveOptions
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function aroundSave(
        ProductRepositoryInterface $subject,
        callable $proceed,
        ProductInterface $product,
        $saveOptions = false
    ): ProductInterface {
        return $this->guard->run(
            self::PROCESS,
            static fn (): ProductInterface => $proceed($product, $saveOptions),
            [
                'label' => self::PROCESS,
                'sku' => (string) $product->getSku(),
            ]
        );
    }
}
