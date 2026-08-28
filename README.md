# Commerce_ProcessGuard

Budgets, reporting and a kill switch for the paths that everything piles onto.

Nobody sets out to put fifty-six observers on the order-placement path. It happens one integration at a time, each one reasonable, over years — and the result is a checkout where one vendor's slow HTTP call is everybody's slow checkout, one vendor's exception is a failed order, and there is no way to find out which vendor without a debugger on production.

One **Place Order** click on a mature installation can dispatch something like:

| Event | Observers | Notes |
| --- | --- | --- |
| `sales_model_service_quote_submit_before` | 20 | runs **inside** the order transaction |
| `sales_model_service_quote_submit_success` | 15 | |
| `sales_order_place_after` | 12 | |
| `checkout_submit_all_after` | 9 | |

Spread across a dozen or more vendors. Magento reports none of it, and offers no way to take one of them out of the path short of patching a vendor package.

---

## What it does

- **Times every observer on a guarded event**, and names the slow one in the log with its class and its milliseconds.
- **Contains failures from observers you have declared advisory**, so a marketing ping that is down does not fail an order.
- **Sheds advisory observers** once a path has blown its budget — if you have turned that on.
- **Switches an observer off entirely** from configuration, without a patch and without a deploy.
- **Counts repeats**, because the classic checkout defect is not a slow totals collector, it is the same collector running six times.
- **Watches memory in long-running processes**, so a consumer climbing towards the limit says so before the kernel does.
- **Lists what is actually on an event**, which nothing in Magento will tell you.

## What it cannot do

**It cannot stop work that has already started.** PHP has no preemption: once an observer is running, nothing short of the process exiting takes the CPU back from it. Budgets are enforced *between* units of work — the next advisory observer is skipped, never the current one. This module makes an overloaded path visible, keeps the non-essential parts of it from making things worse, and gives you a switch. It does not make a slow observer fast.

That is on `ProcessGuardInterface` rather than in a footnote, because a guard that is believed to do more than it does is worse than no guard.

---

## Safety

Installing this changes nothing about what runs.

With `enabled` on and nothing classified, **every observer still runs, still throws, and still aborts whatever it would have aborted**. All that starts is the reporting. Containment and shedding require a person to have named an observer — in `di.xml` where it is reviewed, or in configuration where it is deliberate.

The default is `Measured`, and that is a refusal to be clever. An observer on the order-placement path may be the fraud check, the inventory reservation or the payment capture. A heuristic that decided one of those looked unimportant, and swallowed its exception, would let a broken order through quietly — far worse than the slow checkout this module exists to fix.

| Policy | Failure | Over budget | Set by |
| --- | --- | --- | --- |
| `measured` *(default)* | propagates | still runs | nothing — it is the default |
| `advisory` | logged, contained | skipped, if shedding is on | a human, by name |
| `critical` | propagates | still runs | a human, by name |
| `disabled` | never runs | never runs | a human, by name |

Precedence: the runtime kill list beats the runtime classifications, which beat `di.xml`, which beats the default. The switch someone reaches for at two in the morning wins over the code written before the incident. `critical` is read before `advisory`, so adding a name to the critical list can only make the guard do *less* — which is what makes it safe to type in a hurry.

---

## First run

```bash
bin/magento commerce:process-guard:policies
bin/magento commerce:process-guard:policies --area=frontend
```

That prints every observer on every guarded event, its class, and what the guard would do to it. Read it before classifying anything.

Then watch `var/log/commerce/process_guard.log` for a day. Breaches are logged; routine completions are not.

```
event.sales_order_place_after: vendor_reviews_order_sync took 1840.22ms, over budget
quote.collect_totals: 6 calls, budget allows 4 — repeated more often in one request than its budget allows
queue.consumer: 812.4MB in use — memory ceiling crossed, this process is heading for the memory limit
```

Only then decide what is advisory.

---

## Configuring it

Stores → Configuration → Advanced → **Process Guard**, or:

```bash
bin/magento config:set commerce_processguard/enforcement/disabled_observers vendor_broken_observer
bin/magento config:set commerce_processguard/enforcement/advisory_observers vendor_analytics_ping,vendor_marketing_sync
bin/magento config:set commerce_processguard/enforcement/shedding_enabled 1
bin/magento cache:clean config
```

Observers can be named by their `events.xml` name **or** by their class: whoever is reading a stack trace has the class, whoever is reading configuration has the name, and requiring the right one of the two is a way to have the switch not work when it is needed.

`cache:clean config` is not optional, and it is where the switch takes effect: every web request from that point reads the new lists. Each PHP process settles them once and then holds them — this method runs for every observer of every event, and consulting three config values per observer costs about a hundred and seventy reads to place one order. **A queue consumer already running is the exception**: it settled its lists when it started, so an observer disabled mid-incident stops running on the storefront immediately and keeps running in that consumer until it is restarted. `queue:consumers:start` again after the flip if the observer you are containing is one a consumer reaches.

Classifications that belong in the repository rather than in an incident go in `di.xml`:

```xml
<type name="Commerce\ProcessGuard\Model\Policy\ObserverPolicyResolver">
    <arguments>
        <argument name="classifications" xsi:type="array">
            <item name="vendor_analytics_order_ping" xsi:type="string">advisory</item>
            <item name="inventory_reservation" xsi:type="string">critical</item>
        </argument>
    </arguments>
</type>
```

A misspelled policy is ignored rather than guessed at — losing an order to a typo in an XML file is not a failure mode worth having.

---

## What is guarded, and what it costs

Events, out of the box: the four order-placement events, `checkout_cart_product_add_after`, both totals-collection events, and both product-save events. Everything else dispatches exactly as before — an unguarded event costs one `mb_strtolower` and one `isset`, which matters when the event is `controller_action_predispatch` on every request.

Processes, out of the box:

| Process | Budget | Why that shape |
| --- | --- | --- |
| `event.<name>` | 1000ms warn / 4000ms trip on the placement events | cumulative across all observers of that event |
| `quote.collect_totals` | 1500ms warn, **max 4 calls** | no trip: totals cannot be skipped, the answer would be wrong prices |
| `catalog.product_save` | 2000ms warn | the path an import spends its life on |
| `queue.consumer` | 120s warn, 768MB ceiling | consumers fail on memory, not on latency |

**Every number there is a starting point, not a measurement.** Tune them from your own reports. A budget nobody has calibrated produces warnings people learn to ignore, which is worse than no budget at all. A process with no budget is unlimited, deliberately: inventing a threshold is how a monitoring tool becomes the incident.

---

## Where it hooks in

| Seam | Why that one |
| --- | --- |
| `Event\Invoker\InvokerDefault::dispatch` | the only place where one observer's invocation is a single call with its configuration in hand — `Event\Manager::dispatch` gives the event but not the boundary between observers, which is the boundary a budget needs |
| `Quote\TotalsCollector::collect` / `collectQuoteTotals` | both entry points, counted as one process, because collecting twice by two routes is still collecting twice |
| `ProductRepositoryInterface::save` | the whole save, so its number and the observers' numbers together say which half is the problem |
| `MessageQueue\ConsumerInterface::process` | declared on the interface, because there are several implementations and a monitor that covers some of them is a monitor whose silence means nothing |

---

## Using the guard directly

Anything worth naming can be wrapped, which is how cron jobs and exports get covered — there is no safe universal seam for those:

```php
$this->guard->run('erp.nightly_export', function (): void {
    foreach ($this->batches() as $batch) {
        $this->export($batch);
        $this->guard->checkpoint('erp.nightly_export', ['batch' => $batch->getId()]);
    }
});
```

`run()` **never swallows**. An exception propagates after the cost is recorded — containment is a decision about observers, taken one observer at a time, and a process wrapper that ate exceptions would be a way to lose an order.

---

## Gotchas

- **Turning on shedding is the only setting that changes what runs.** Everything else is measurement. Read a report first.
- **Budgets are cumulative per request**, not per call. The failure that matters is a path costing four seconds, whether that was one observer or forty.
- **Each breach is reported once per process per request.** A guard that logged every call after the first breach would turn one slow path into a hundred thousand log lines.
- **The journal's bound applies to detail, not to counts.** Once it is full it keeps aggregating and stops keeping individual observations, and the report says so. A noteworthy observation displaces a routine one, so the one failure in ten thousand dispatches is not the entry that gets dropped.
- **The policy listing is per area.** A CLI process is in `global`, so a storefront event's observers are simply absent from its configuration — pass `--area=frontend` to see them.
- **The plugin declarations are not covered by the unit suite.** It proves the gate's logic exactly, and cannot prove that `di.xml` still binds to Magento's signatures after an upgrade. `bin/magento setup:di:compile` on a real installation is the check that catches that.

---

## Tests

93 unit tests, no database and no real clock:

```bash
M2_VENDOR=/path/to/magento/vendor php ../dev/run-tests.php -c ../dev/phpunit.xml
```

The precedence order, the containment rules, the three conditions for shedding, the cumulative budgets, the once-per-request reporting and the journal's bound are each asserted directly — those are the behaviours that decide whether an order is placed.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```

Then move the configuration:

```sql
UPDATE core_config_data SET path = REPLACE(path, 'commerce_processguard', 'acme_processguard')
 WHERE path LIKE 'commerce_processguard/%';
```
