<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Plugin\Quote;

use Commerce\ProcessGuard\Api\ProcessGuardInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\TotalsCollector;

/**
 * Times totals collection, and counts how often it happens.
 */
class GuardedTotalsCollector
{
    public const PROCESS = 'quote.collect_totals';

    public function __construct(
        private readonly ProcessGuardInterface $guard
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCollect(
        TotalsCollector $subject,
        callable $proceed,
        Quote $quote
    ): Total {
        return $this->guard->run(
            self::PROCESS,
            static fn (): Total => $proceed($quote),
            $this->context($quote, 'collect')
        );
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCollectQuoteTotals(
        TotalsCollector $subject,
        callable $proceed,
        Quote $quote
    ): Quote {
        return $this->guard->run(
            self::PROCESS,
            static fn (): Quote => $proceed($quote),
            $this->context($quote, 'collectQuoteTotals')
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function context(Quote $quote, string $entry): array
    {
        return [
            'label' => self::PROCESS . ':' . $entry,
            // The quote id makes a report actionable: "this cart" rather than
            // "some cart".
            'quote_id' => (int) $quote->getId(),
            'items' => (int) $quote->getItemsCount(),
        ];
    }
}
