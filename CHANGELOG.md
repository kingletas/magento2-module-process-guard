# Changelog

## Unreleased

Tooling only. PHPStan and PHPMD pass. The caller resolver reads a backtrace
frame's function name directly, since PHP always sets it, and two offsets are
named `$marker` rather than `$at`. Nothing about how the module behaves changed.

Requires `kingletas/module-foundation` 2.1 or later, whose wiring assertions the
test suite now uses. Nothing about how the module behaves changed.

Documentation only. `docs/recommended-settings.md` covers the totals breakdown
setting and the new check command, and says which of the two console commands
can gate a deploy. Nothing about how the module behaves changed.

Totals collection can now be broken down into the collectors it is made of. `Break Down Totals Collection` in the Reporting section times each collector separately, so a report that said `quote.collect_totals` was slow now says which collector spent the time, in the collection summary when summaries are switched on. One slow collector can account for most of a collection, and until now nothing in Magento could say which one.

The same setting records the code that asked for each collection. The guard already reported `5 calls, budget allows 4`; it can now name the plugin that asked for the fifth, which is the difference between knowing there is a redundant collection and being able to go and remove it. Both cost real time and both are off by default.

`bin/magento kingletas:process-guard:check` is a new command, and it is silent when there is nothing wrong. An observer classified as disabled, advisory or critical that is on none of the guarded events was accepted by the configuration field, saved, listed in the policy report and had no effect whatsoever. The command names any such entry, names the setting it came from, lists the events that are watched and exits non-zero, so a deploy can refuse a setting that does nothing.

Accounting now resets at the boundary of a unit of work rather than running for the life of the PHP process. A consumer that handles thousands of messages measured all of them against one budget, crossed it in the first minutes and could never come back under it, so the `queue.consumer` warning fired for the rest of the run and stopped carrying information. One message is now one unit of work, and so is one cron job. A process that is still running spans the boundary, because a consumer's own budget covers the consumer rather than one message inside it.

`bin/magento kingletas:process-guard:policies` now prints the budget every guarded process is judged against: its warn and trip times, its call ceiling and its memory ceiling. Those numbers only existed in `di.xml`, so an operator who read `over budget` in the log had to open a file under `vendor/` to find out what the budget was, while the screen that tells them to run that command promised a decision taken against evidence.

Budgets are configured exactly as they were in 2.0.0: the `budgets` array argument of `ProcessGuard`, where a store's own `di.xml` can add a budget or replace a shipped one by name. The shipped budgets now arrive through a separate `budgetDirectory` argument, each entry in `budgets` replaces the shipped budget of the same name, and the policy report prints the same merged set the guard judges against. A store upgrading in place from 2.0.0 keeps working, both while its cached wiring still has the old shape and with any budgets it tuned itself.

An advisory observer is no longer described as skipped while shedding is off. The row said `contain failures, skip when over budget` whatever the setting, and with shedding off an advisory observer always runs. It now says which of the two is true.

The Measurement screen and the README named the report as `var/log/kingletas/process_guard.log`. The handler rotates daily, so that file never exists; both now name `process_guard-<date>.log`.

Tooling only. The wiring suite fails when an encrypted admin field has no
sensitive declaration, so the next credential cannot ship undeclared. Nothing
about how the module behaves changed.

Documentation only. `docs/recommended-settings.md` says what to set on a
production store and why, and the README links to it. Nothing about how the
module behaves changed.

Tooling only. Release notes join each changelog paragraph onto one line,
because a release page turns every newline into a line break. Nothing about
how the module behaves changed.

## 2.0.0

The vendor is now Kingletas: the package is `kingletas/module-process-guard`, the namespace
`Kingletas\ProcessGuard` and the module `Kingletas_ProcessGuard`, and every config
section, table, console command and queue name starts with `kingletas`
instead of `commerce`. Nothing about how the module behaves changed; an
existing install moves its `commerce_` config rows and tables to `kingletas_`.

Tooling only. `make test` and `make cs` read Magento and the tools from this
package's own `vendor/`, which `make install` fills, and stop with instructions
when it is missing rather than running whatever `phpcs` or `phpunit` is on the
PATH. Nothing about how the module behaves changed.

## 1.0.3

Tooling only. Every workflow action is pinned to a commit rather than a tag, and
static analysis moved to PHPStan 2. Nothing about how the module behaves changed.

## 1.0.2

Mess detection runs through the module's own composer script, so `composer md`
and the CI gate ask for exactly the same thing.

## Earlier

This module is developed alongside fourteen others and published here from that
tree. The releases before 1.0.2 are in the tags, and the reasoning behind
each one is in the commits.
