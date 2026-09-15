<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Console\Command;

use Kingletas\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Kingletas\ProcessGuard\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Event\Config\Data as EventConfigData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Silent unless an observer has been classified that the guard can never reach.
 */
class CheckConfigurationCommand extends Command
{
    /**
     * Observers are merged per area, and an observer named in configuration may
     * live in any of them.
     */
    private const AREAS = [
        Area::AREA_GLOBAL,
        Area::AREA_FRONTEND,
        Area::AREA_ADMINHTML,
        Area::AREA_CRONTAB,
        Area::AREA_WEBAPI_REST,
    ];

    public function __construct(
        private readonly ObserverPolicyResolverInterface $policyResolver,
        private readonly EventConfigData $eventConfig,
        private readonly ScopeInterface $configScope,
        private readonly Config $config,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Check that every classified observer is one the guard watches. Silent when they all are.'
        );

        parent::configure();
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $events = $this->policyResolver->getGuardedEvents();

        if ($events === []) {
            $output->writeln('<error>No events are guarded, so no classification can take effect.</error>');

            return Command::FAILURE;
        }

        $reachable = $this->reachable($events);
        $unreachable = [];

        foreach ($this->classified() as $field => $entries) {
            foreach ($entries as $entry) {
                if (!isset($reachable[mb_strtolower(trim($entry))])) {
                    $unreachable[] = [$field, $entry];
                }
            }
        }

        if ($unreachable === []) {
            return Command::SUCCESS;
        }

        $this->report($output, $unreachable, $events);

        return Command::FAILURE;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $unreachable
     * @param string[] $events
     */
    private function report(OutputInterface $output, array $unreachable, array $events): void
    {
        $output->writeln(sprintf(
            '<error>%d classified observer(s) are on no guarded event, so the setting does nothing.</error>',
            count($unreachable)
        ));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Setting', 'Observer']);
        $table->setRows($unreachable);
        $table->render();

        $output->writeln('');
        $output->writeln('The guard watches these events, and only these:');

        foreach ($events as $event) {
            $output->writeln('  ' . $event);
        }

        $output->writeln('');
        $output->writeln(
            'Either the name is wrong, or the observer is on an event this guard does not watch. '
            . 'Adding an event is the guardedEvents argument in di.xml.'
        );
    }

    /**
     * Both identifiers of every observer on every guarded event, in every area.
     *
     * @param string[] $events
     * @return array<string, true>
     */
    private function reachable(array $events): array
    {
        $known = $this->configScope->getCurrentScope();
        $reachable = [];

        try {
            foreach (self::AREAS as $area) {
                $this->configScope->setCurrentScope($area);

                foreach ($events as $event) {
                    /** @var array<string, array<string, mixed>> $observers */
                    $observers = $this->eventConfig->get($event, []);

                    foreach ($observers as $key => $observer) {
                        $reachable[mb_strtolower((string) ($observer['name'] ?? $key))] = true;
                        $reachable[mb_strtolower((string) ($observer['instance'] ?? ''))] = true;
                    }
                }
            }
        } finally {
            // The scope is global machinery; leaving it somewhere else would
            // change what every later command reads.
            $this->configScope->setCurrentScope($known);
        }

        unset($reachable['']);

        return $reachable;
    }

    /**
     * @return array<string, string[]>
     */
    private function classified(): array
    {
        return [
            'disabled_observers' => $this->safely([$this->config, 'getDisabledObservers']),
            'advisory_observers' => $this->safely([$this->config, 'getAdvisoryObservers']),
            'critical_observers' => $this->safely([$this->config, 'getCriticalObservers']),
        ];
    }

    /**
     * @return string[]
     */
    private function safely(callable $read): array
    {
        try {
            return array_filter(array_map('trim', $read()));
        } catch (Throwable) {
            // A store that cannot be read is not a misconfigured observer.
            return [];
        }
    }
}
