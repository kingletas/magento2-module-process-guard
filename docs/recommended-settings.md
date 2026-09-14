# Recommended settings

What to set on a production store, and why. Six settings, and the recommendation for most of them is to leave them alone until you have a week of your own log.

## Contents

- [The short version](#the-short-version)
- [Why most of it stays off](#why-most-of-it-stays-off)
- [Classify the critical list first](#classify-the-critical-list-first)
- [Then read the log for a week](#then-read-the-log-for-a-week)
- [Classifications that belong in the repository](#classifications-that-belong-in-the-repository)
- [Budgets](#budgets)
- [Making a change take effect](#making-a-change-take-effect)
- [Where the settings can be set](#where-the-settings-can-be-set)
- [Why this one cannot be a deploy gate](#why-this-one-cannot-be-a-deploy-gate)

## The short version

| Setting | Production value | Why |
|---|---|---|
| `general/enabled` | `1` | Measurement only. An unguarded event costs one `mb_strtolower` and one `isset` |
| `reporting/summaries_enabled` | `0` | Breaches are always logged. Summaries are every process every time, which is most of the volume |
| `enforcement/disabled_observers` | empty | This is the incident switch. Useful at two in the morning, wrong as a standing setting |
| `enforcement/shedding_enabled` | `0` | The only setting that changes what runs. It needs budgets calibrated against your traffic |
| `enforcement/critical_observers` | the ones that decide stock and money | Adding a name here can only make the guard do less |
| `enforcement/advisory_observers` | from your own log, after a week | The test is whether you would fail an order over it |

```bash
bin/magento config:set kingletas_processguard/general/enabled 1
```

```bash
bin/magento config:set kingletas_processguard/reporting/summaries_enabled 0
```

```bash
bin/magento cache:clean config
```

## Why most of it stays off

With measurement on and nothing classified, **every observer still runs, still throws, and still aborts whatever it would have aborted.** All that starts is the reporting.

The default classification is `measured`, and that is a refusal to be clever. An observer on the order-placement path may be the fraud check, the inventory reservation or the payment capture. A heuristic that decided one of those looked unimportant, and swallowed its exception, would let a broken order through quietly. That is far worse than the slow checkout this module exists to make visible.

So the order is: measure, read, then classify. Not the other way round.

## Classify the critical list first

Read the listing before writing anything into either list:

```bash
bin/magento kingletas:process-guard:policies --area frontend
```

**Pass `--area frontend`.** The default area is `global`, and a storefront order runs observers that `global` has never heard of, including the order confirmation email.

Critical is the safe direction, which is why it goes first. Precedence runs the kill list, then the runtime classifications, then `di.xml`, then the default, and `critical` is read before `advisory`. So a name on the critical list overrides a vendor's own `di.xml` declaration that something is expendable.

Today, with nothing advisory and shedding off, `critical` and `measured` behave the same. **The point of writing it down is that it keeps behaving correctly after somebody else edits the advisory list.**

These core classes are the usual starting set. Check which of them exist on your store before pasting the list, because naming an observer that is not installed buys nothing:

| Observer class | What it decides |
|---|---|
| `Magento\CatalogInventory\Observer\SubtractQuoteInventoryObserver` | Takes stock off the shelf when an order is placed |
| `Magento\CatalogInventory\Observer\CheckoutAllSubmitAfterObserver` | Finalises stock after checkout |
| `Magento\CatalogInventory\Observer\ProcessInventoryDataObserver` | Stock data on a product save |
| `Magento\CatalogInventory\Observer\SaveInventoryDataObserver` | Writes that stock data |
| `Magento\Quote\Observer\SetBasePriceObserver` | The price an item enters the cart at |

**Use class names rather than `events.xml` names.** Both are accepted, deliberately, because whoever is reading a stack trace has the class and whoever is reading configuration has the name. The class is the unambiguous one: a name like `inventory` is reused on four different events and would catch all four at once.

## Then read the log for a week

Leave the advisory list empty and watch `var/log/kingletas/process_guard-<date>.log`, which rotates daily and keeps seven files.

Breaches are logged at WARNING. Routine completions are not, so the log stays empty until something is genuinely slow, and an empty log is the healthy state rather than a broken one.

```text
event.sales_order_place_after: vendor_reviews_order_sync took 1840.22ms, over budget
quote.collect_totals: 6 calls, budget allows 4 — repeated more often in one request than its budget allows
```

**Classify from what it showed you, not from what you expected it to show.** The core classes above are on every installation; the ones that actually matter are the fifty-odd that accumulated one integration at a time, and only your log names them.

The two email observers are the usual first entries once the log confirms them. A shopper whose payment succeeded should not see an order failure because the mail server was slow:

| Observer class | Why advisory |
|---|---|
| `Magento\Quote\Observer\SubmitObserver` | Sends the order confirmation email |
| `Magento\Quote\Observer\SendInvoiceEmailObserver` | Sends the invoice email |

With shedding off they still always run, so nobody loses an email. What changes is that a throw is contained instead of reaching the shopper.

## Classifications that belong in the repository

An incident switch belongs in configuration. A standing decision belongs in `di.xml`, where it is reviewed:

```xml
<type name="Kingletas\ProcessGuard\Model\Policy\ObserverPolicyResolver">
    <arguments>
        <argument name="classifications" xsi:type="array">
            <item name="vendor_analytics_order_ping" xsi:type="string">advisory</item>
            <item name="inventory_reservation" xsi:type="string">critical</item>
        </argument>
    </arguments>
</type>
```

## Budgets

**Leave the shipped budgets alone until you have your own numbers.** They are starting points rather than measurements.

| Process | Warn | Trip | Max calls | Memory |
|---|---:|---:|---:|---:|
| `event.sales_*` and `event.checkout_submit_all_after` | 1000ms | 4000ms | none | none |
| `event.catalog_product_save_*` | 750ms | 3000ms | none | none |
| `quote.collect_totals` | 1500ms | none | 4 | none |
| `catalog.product_save` | 2000ms | none | none | none |
| `queue.consumer` | 120000ms | none | none | 768 MiB |

Two things in that table are decisions rather than gaps. **`quote.collect_totals` has no trip value on purpose**, because a totals collection cannot be skipped: the answer would be wrong prices. And **a process with no budget is unlimited on purpose**, because inventing a threshold nobody has measured is how a monitoring tool becomes the incident.

When you do tune them, set `warn` near the 95th percentile of your own measurements and `trip` at a number you would rather fail than exceed. `warn` is clamped to `min(warn, trip)`, so nothing can trip without having been warned about first.

A budget nobody has calibrated produces warnings people learn to ignore, which is worse than no budget at all.

## Making a change take effect

```bash
bin/magento cache:clean config
```

This is not optional, and it is where the switch takes effect. Every web request from that point reads the new lists.

Each PHP process settles the lists once and then holds them, which is what keeps the cost at about a hundred and seventy config reads to place an order rather than one per observer per event.

**A queue consumer that is already running is the exception.** It settled its lists when it started, so an observer disabled mid-incident stops running on the storefront immediately and keeps running in that consumer until it is restarted. Start the consumer again after the change if the observer you are containing is one it reaches.

## Where the settings can be set

**Every setting is global.** All six fields are editable at default scope only, so you cannot classify an observer for one website and not another.

## Why this one cannot be a deploy gate

`kingletas:process-guard:policies` is a listing. It exits `0` in every case except an unknown `--area`, so there is nothing for a deploy check to assert on.

**That is worth saying out loud rather than leaving for somebody to discover.** The way you find out this module has stopped measuring is that its log went quiet, and nothing is watching for that. If you want a check, assert that the log file exists and has been written to inside the retention window.

## Where to go next

- [README](../README.md)
