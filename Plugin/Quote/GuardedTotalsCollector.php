<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Plugin\Quote;

use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
use Kingletas\ProcessGuard\Model\Config;
use Kingletas\ProcessGuard\Model\Guard\CallerResolver;
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
        private readonly ProcessGuardInterface $guard,
        private readonly Config $config,
        private readonly CallerResolver $callers
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
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
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
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
        $context = [
            'label' => self::PROCESS . ':' . $entry,
            // The quote id makes a report actionable: "this cart" rather than
            // "some cart".
            'quote_id' => (int) $quote->getId(),
            'items' => (int) $quote->getItemsCount(),
        ];

        // A backtrace costs more than the rest of this method, and it is the
        // only thing that turns "collected five times, budget allows four"
        // into a place to go and look.
        if ($this->config->isTotalsDetailEnabled()) {
            $context['asked_by'] = $this->callers->resolve();
        }

        return $context;
    }
}
