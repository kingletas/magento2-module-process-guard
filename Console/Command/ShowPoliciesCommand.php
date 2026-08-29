<?php
/**
 * @package   Commerce_ProcessGuard
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ProcessGuard\Console\Command;

use Commerce\ProcessGuard\Api\ObserverPolicy;
use Commerce\ProcessGuard\Api\ObserverPolicyResolverInterface;
use Commerce\ProcessGuard\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Event\Config\Data as EventConfigData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers "what actually happens on this event, and to whom".
 */
class ShowPoliciesCommand extends Command
{
    private const OPTION_AREA = 'area';

    /**
     * Observers are merged per area, and a CLI process is in `global`.
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
        $this->setDescription('List the observers on every guarded event and what the guard would do to each.')
            ->addOption(
                self::OPTION_AREA,
                'a',
                InputOption::VALUE_REQUIRED,
                sprintf('Which area\'s observers to list: %s.', implode(', ', self::AREAS)),
                Area::AREA_GLOBAL
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $area = (string) $input->getOption(self::OPTION_AREA);

        if (!in_array($area, self::AREAS, true)) {
            $output->writeln(sprintf('<error>Unknown area "%s".</error>', $area));
            $output->writeln('Areas: ' . implode(', ', self::AREAS));

            return Command::INVALID;
        }

        $events = $this->policyResolver->getGuardedEvents();

        if ($events === []) {
            $output->writeln('<comment>No events are guarded. See the guardedEvents argument in di.xml.</comment>');

            return Command::SUCCESS;
        }

        $this->printState($output, $area);

        // Observer configuration is read through the current config scope, so
        // listing another area enters it.
        $this->configScope->setCurrentScope($area);

        $total = 0;

        foreach ($events as $eventName) {
            $total += $this->printEvent($output, $eventName);
        }

        $output->writeln(sprintf('<info>%d observer(s) across %d guarded event(s).</info>', $total, count($events)));

        return Command::SUCCESS;
    }

    private function printEvent(OutputInterface $output, string $eventName): int
    {
        /** @var array<string, array<string, mixed>> $observers */
        $observers = $this->eventConfig->get($eventName, []);

        $output->writeln('');
        $output->writeln(sprintf('<info>%s</info> (%d observer(s))', $eventName, count($observers)));

        if ($observers === []) {
            return 0;
        }

        $table = new Table($output);
        $table->setHeaders(['Observer', 'Class', 'Guard does']);

        foreach ($observers as $key => $observer) {
            $observerName = (string) ($observer['name'] ?? $key);
            $instance = (string) ($observer['instance'] ?? '');

            $table->addRow([
                $observerName,
                $instance,
                $this->describe($this->policyResolver->resolve($eventName, $observerName, $instance)),
            ]);
        }

        $table->render();

        return count($observers);
    }

    private function printState(OutputInterface $output, string $area): void
    {
        $output->writeln(sprintf('Area: <info>%s</info>', $area));
        $output->writeln(sprintf(
            'Measurement: <info>%s</info>   Shedding: <info>%s</info>',
            $this->config->isEnabled() ? 'on' : 'off',
            $this->config->isSheddingEnabled() ? 'on' : 'off'
        ));

        if (!$this->config->isEnabled()) {
            // Said plainly: a policy table printed by a switched-off module
            // describes only what would happen.
            $output->writeln('<comment>The guard is switched off: nothing below is being applied.</comment>');
        }
    }

    private function describe(ObserverPolicy $policy): string
    {
        return match ($policy) {
            ObserverPolicy::Measured => 'time it, report if slow',
            ObserverPolicy::Advisory => '<comment>contain failures, skip when over budget</comment>',
            ObserverPolicy::Critical => 'time it, never skip or contain',
            ObserverPolicy::Disabled => '<error>never run it</error>',
        };
    }
}
