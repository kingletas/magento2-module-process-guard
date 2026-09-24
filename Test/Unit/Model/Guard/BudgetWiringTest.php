<?php
/**
 * @package   Kingletas_ProcessGuard
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ProcessGuard\Test\Unit\Model\Guard;

use Kingletas\Foundation\Test\Support\CountingScopeConfig;
use Kingletas\Foundation\Test\Support\ObjectManagerIsolation;
use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
use Kingletas\ProcessGuard\Api\ProcessJournalInterface;
use Kingletas\ProcessGuard\Api\ProcessReporterInterface;
use Kingletas\ProcessGuard\Console\Command\ShowPoliciesCommand;
use Kingletas\ProcessGuard\Model\Guard\ProcessGuard;
use Kingletas\ProcessGuard\Model\Journal\ObservationOutcome;
use Kingletas\ProcessGuard\Model\Journal\ObservationRecorder;
use Kingletas\ProcessGuard\Model\Report\LogReporter;
use Magento\Framework\App\Arguments\ArgumentInterpreter;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\Data\Argument\Interpreter\ArrayType;
use Magento\Framework\Data\Argument\Interpreter\BaseStringUtils;
use Magento\Framework\Data\Argument\Interpreter\Boolean;
use Magento\Framework\Data\Argument\Interpreter\Composite;
use Magento\Framework\Data\Argument\Interpreter\Constant;
use Magento\Framework\Data\Argument\Interpreter\DataObject;
use Magento\Framework\Data\Argument\Interpreter\NullType;
use Magento\Framework\Data\Argument\Interpreter\Number;
use Magento\Framework\Event\Config\Data as EventConfigData;
use Magento\Framework\ObjectManager\Config\Config;
use Magento\Framework\ObjectManager\Config\Mapper\Dom as DomMapper;
use Magento\Framework\ObjectManager\Config\Reader\Dom as DomReader;
use Magento\Framework\ObjectManager\Config\SchemaLocator;
use Magento\Framework\ObjectManager\Definition\Runtime as RuntimeDefinition;
use Magento\Framework\ObjectManager\Factory\Dynamic\Developer;
use Magento\Framework\ObjectManager\ObjectManager;
use Magento\Framework\ObjectManager\Relations\Runtime as RuntimeRelations;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\BooleanUtils;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The guard's budgets under the shipped wiring, 2.0.0's cached wiring and a
 * store's own array, built as Magento builds them.
 */
class BudgetWiringTest extends TestCase
{
    use ObjectManagerIsolation;

    private bool $isolated = false;

    /**
     * Each object manager's shared instances, held by reference.
     *
     * @var array<int, array<string, object>>
     */
    private array $sharedInstances = [];

    /**
     * What the README says ships, and 2.0.0 shipped the same names.
     */
    private const SHIPPED = [
        'event.sales_model_service_quote_submit_before',
        'event.sales_model_service_quote_submit_success',
        'event.sales_order_place_after',
        'event.checkout_submit_all_after',
        'event.catalog_product_save_before',
        'event.catalog_product_save_after',
        'quote.collect_totals',
        'catalog.product_save',
        'queue.consumer',
    ];

    /**
     * A store's own di.xml: one invented process, and a looser ceiling on one
     * the module ships.
     */
    private const STORE_DI = <<<'XML'
        <?xml version="1.0"?>
        <config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
            <virtualType name="Acme\Store\InventedBudget" type="Kingletas\ProcessGuard\Model\Guard\Budget">
                <arguments>
                    <argument name="warnMilliseconds" xsi:type="number">1234</argument>
                </arguments>
            </virtualType>
            <virtualType name="Acme\Store\LooserTotalsBudget" type="Kingletas\ProcessGuard\Model\Guard\Budget">
                <arguments>
                    <argument name="warnMilliseconds" xsi:type="number">1500</argument>
                    <argument name="maxCalls" xsi:type="number">9</argument>
                </arguments>
            </virtualType>
            <type name="Kingletas\ProcessGuard\Model\Guard\ProcessGuard">
                <arguments>
                    <argument name="budgets" xsi:type="array">
                        <item name="acme.invented_export" xsi:type="object">Acme\Store\InventedBudget</item>
                        <item name="quote.collect_totals" xsi:type="object">Acme\Store\LooserTotalsBudget</item>
                    </argument>
                </arguments>
            </type>
        </config>
        XML;

    /**
     * The console wiring a store's app/etc/di.xml supplies.
     */
    private const CONSOLE_DI = <<<'XML'
        <?xml version="1.0"?>
        <config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
            <preference for="Magento\Framework\Console\CommandListInterface"
                        type="Magento\Framework\Console\CommandList"/>
        </config>
        XML;

    protected function tearDown(): void
    {
        $this->releaseObjectManager();
    }

    public function testTheShippedWiringGivesTheGuardEveryShippedBudget(): void
    {
        $objectManager = $this->objectManager(['etc/di.xml' => $this->shippedDi()]);

        $budgets = $this->guard($objectManager)->getBudgets()->all();

        $this->assertEqualsCanonicalizing(self::SHIPPED, array_keys($budgets));
        $this->assertSame(4, $budgets['quote.collect_totals']->getMaxCalls());
    }

    /**
     * A store upgrading in place from 2.0.0 still has 2.0.0's wiring cached
     * until something flushes it, and bin/magento cache:flush has to build the
     * guard to get that far.
     */
    public function testTheWiring200ShippedStillBuildsTheGuardWithItsBudgets(): void
    {
        $objectManager = $this->objectManager(['etc/di.xml' => $this->di200()]);

        $budgets = $this->guard($objectManager)->getBudgets()->all();

        $this->assertEqualsCanonicalizing(self::SHIPPED, array_keys($budgets));
        $this->assertSame(4, $budgets['quote.collect_totals']->getMaxCalls());
    }

    public function testAStoreArrayAddsToTheShippedBudgetsAndOverridesOneByName(): void
    {
        $objectManager = $this->objectManager([
            'etc/di.xml' => $this->shippedDi(),
            'store/etc/di.xml' => self::STORE_DI,
        ]);

        $budgets = $this->guard($objectManager)->getBudgets()->all();

        $this->assertEqualsCanonicalizing(
            array_merge(self::SHIPPED, ['acme.invented_export']),
            array_keys($budgets)
        );
        $this->assertSame(1234, $budgets['acme.invented_export']->toArray()['warn_ms']);
        $this->assertSame(9, $budgets['quote.collect_totals']->getMaxCalls(), 'the store wins over the directory');
        $this->assertSame(805306368, $budgets['queue.consumer']->getMemoryBytes(), 'the rest still ship');
    }

    /**
     * The guard acting on the merged budgets, not only listing them: five
     * collections break the shipped ceiling of four and not the store's nine.
     */
    public function testTheGuardJudgesAProcessByTheStoresBudgetWhenItNamesOne(): void
    {
        $shipped = $this->objectManager(['etc/di.xml' => $this->shippedDi()]);
        $tuned = $this->objectManager([
            'etc/di.xml' => $this->shippedDi(),
            'store/etc/di.xml' => self::STORE_DI,
        ]);

        $this->assertSame(1, $this->repeatsAfterFiveCollections($shipped));
        $this->assertSame(0, $this->repeatsAfterFiveCollections($tuned));
    }

    public function testTheReportPrintsTheSameMergedBudgetsTheGuardUses(): void
    {
        $objectManager = $this->objectManager([
            'etc/di.xml' => $this->shippedDi(),
            'store/etc/di.xml' => self::STORE_DI,
        ]);

        $rows = $this->budgetRows($objectManager);

        $this->assertContains('| acme.invented_export | 1234ms | none | none | none |', $rows, implode("\n", $rows));
        $this->assertContains('| quote.collect_totals | 1500ms | none | 9 | none |', $rows, implode("\n", $rows));
        $this->assertContains('| queue.consumer | 120000ms | none | none | 768MB |', $rows, implode("\n", $rows));
    }

    public function testTheReportPrintsTheBudgets200ShippedFromItsWiring(): void
    {
        $rows = $this->budgetRows($this->objectManager(['etc/di.xml' => $this->di200()]));

        $this->assertContains('| quote.collect_totals | 1500ms | none | 4 | none |', $rows, implode("\n", $rows));
    }

    /**
     * Magento builds every console command on every bin/magento run, so the
     * report takes the guard through its generated proxy.
     */
    public function testTheReportTakesTheGuardThroughItsProxy(): void
    {
        $arguments = $this->diConfig(['etc/di.xml' => $this->shippedDi()])
            ->getArguments(ShowPoliciesCommand::class);

        $this->assertSame(['instance' => ProcessGuard::class . '\\Proxy'], $arguments['guard'] ?? null);
    }

    public function testListingTheCommandsBuildsNoGuardAndRunningTheReportBuildsTheOne(): void
    {
        $objectManager = $this->objectManager([
            'app/etc/console.xml' => self::CONSOLE_DI,
            'etc/di.xml' => $this->shippedDi(),
        ]);

        $commands = $objectManager->get(CommandListInterface::class)->getCommands();

        $this->assertContainsOnlyInstancesOf(Command::class, $commands);
        $this->assertArrayHasKey('kingletas_process_guard_policies', $commands);
        $this->assertFalse($this->hasBuilt($objectManager, ProcessGuard::class), 'listing built the guard');
        $this->assertFalse($this->hasBuilt($objectManager, ObservationRecorder::class), 'listing built the recorder');

        (new CommandTester($commands['kingletas_process_guard_policies']))->execute([]);

        $this->assertTrue($this->hasBuilt($objectManager, ProcessGuard::class));
        $this->assertSame(
            $objectManager->get(ProcessGuardInterface::class),
            $this->sharedInstances[spl_object_id($objectManager)][ProcessGuard::class],
            'the report reads the guard that judges, not a second one'
        );
    }

    private function repeatsAfterFiveCollections(ObjectManagerInterface $objectManager): int
    {
        $guard = $objectManager->get(ProcessGuardInterface::class);

        for ($i = 0; $i < 5; $i++) {
            $guard->run('quote.collect_totals', static fn (): bool => true);
        }

        return $objectManager->get(ProcessJournalInterface::class)
            ->getReport()
            ->getOutcomeCount('quote.collect_totals', ObservationOutcome::Repeated);
    }

    /**
     * The budget table's rows, with the padding squeezed out.
     *
     * @return string[]
     */
    private function budgetRows(ObjectManagerInterface $objectManager): array
    {
        $tester = new CommandTester($objectManager->get(ShowPoliciesCommand::class));
        $tester->execute([]);

        $rows = [];

        foreach (explode("\n", $tester->getDisplay()) as $line) {
            if (str_starts_with($line, '| ')) {
                $rows[] = (string) preg_replace('/ {2,}/', ' ', rtrim($line));
            }
        }

        return $rows;
    }

    private function guard(ObjectManagerInterface $objectManager): ProcessGuard
    {
        $guard = $objectManager->get(ProcessGuardInterface::class);
        $this->assertInstanceOf(ProcessGuard::class, $guard);

        return $guard;
    }

    /**
     * An object manager configured the way Magento configures one: the files
     * merged by `Reader\Dom`, mapped by `Mapper\Dom` through the argument
     * interpreter `ObjectManagerFactory` builds, and built by the developer
     * factory. Only what lies outside this module is stood in for: the store's
     * configuration, the log, the event configuration and the config scope.
     *
     * @param array<string, string> $files Path => di.xml content, merged in order.
     */
    private function objectManager(array $files): ObjectManagerInterface
    {
        $definitions = new RuntimeDefinition();
        $config = $this->diConfig($files, $definitions);

        $eventConfig = $this->createStub(EventConfigData::class);
        $eventConfig->method('get')->willReturn([]);

        // Keyed by the type the object manager resolves a request to, which for
        // the reporter is the preference's target.
        $shared = [
            ScopeConfigInterface::class => new CountingScopeConfig(['kingletas_processguard/general/enabled' => '1']),
            LogReporter::class => $this->createStub(ProcessReporterInterface::class),
            EventConfigData::class => $eventConfig,
            ScopeInterface::class => $this->createStub(ScopeInterface::class),
        ];

        $factory = new Developer($config, null, $definitions);
        $objectManager = new ObjectManager($factory, $config, $shared);
        $factory->setObjectManager($objectManager);
        $this->sharedInstances[spl_object_id($objectManager)] = &$shared;

        // Isolated once per test, so tearDown puts back what was there before
        // the first, however many this test builds.
        if ($this->isolated) {
            AppObjectManager::setInstance($objectManager);
        } else {
            $this->useObjectManager($objectManager);
            $this->isolated = true;
        }

        return $objectManager;
    }

    /**
     * Whether this object manager has built a shared instance of the type yet.
     */
    private function hasBuilt(ObjectManagerInterface $objectManager, string $type): bool
    {
        return isset($this->sharedInstances[spl_object_id($objectManager)][$type]);
    }

    /**
     * The files merged by `Reader\Dom` and mapped by `Mapper\Dom`, as a store reads them.
     *
     * @param array<string, string> $files Path => di.xml content, merged in order.
     */
    private function diConfig(array $files, ?RuntimeDefinition $definitions = null): Config
    {
        // A store always merges this module's file into others, which is what
        // folds its two <type> blocks for the report into one.
        $base = ['app/etc/di.xml' => '<?xml version="1.0"?><config/>'];

        $fileResolver = $this->createStub(FileResolverInterface::class);
        $fileResolver->method('get')->willReturn($base + $files);

        $validation = $this->createStub(ValidationStateInterface::class);
        $validation->method('isValidationRequired')->willReturn(false);

        $reader = new DomReader(
            $fileResolver,
            new DomMapper($this->argumentInterpreter()),
            $this->createStub(SchemaLocator::class),
            $validation
        );

        $config = new Config(new RuntimeRelations(), $definitions ?? new RuntimeDefinition());
        $config->extend($reader->read('global'));

        return $config;
    }

    /**
     * The interpreter `ObjectManagerFactory::createArgumentInterpreter()` builds.
     */
    private function argumentInterpreter(): Composite
    {
        $booleanUtils = new BooleanUtils();
        $constant = new Constant();
        $interpreter = new Composite(
            [
                'boolean' => new Boolean($booleanUtils),
                'string' => new BaseStringUtils($booleanUtils),
                'number' => new Number(),
                'null' => new NullType(),
                'object' => new DataObject($booleanUtils),
                'const' => $constant,
                'init_parameter' => new ArgumentInterpreter($constant),
            ],
            DomReader::TYPE_ATTRIBUTE
        );
        $interpreter->addInterpreter('array', new ArrayType($interpreter));

        return $interpreter;
    }

    private function shippedDi(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml');
    }

    /**
     * etc/di.xml exactly as 2.0.0 shipped it.
     */
    private function di200(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/di-2.0.0.xml');
    }
}
