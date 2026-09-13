# Changelog

## Unreleased

`bin/magento kingletas:process-guard:policies` now prints the budget every guarded process is judged against: its warn and trip times, its call ceiling and its memory ceiling. Those numbers only existed in `di.xml`, so an operator who read `over budget` in the log had to open a file under `vendor/` to find out what the budget was, while the screen that tells them to run that command promised a decision taken against evidence.

An advisory observer is no longer described as skipped while shedding is off. The row said `contain failures, skip when over budget` whatever the setting, and with shedding off an advisory observer always runs. It now says which of the two is true.

The Measurement screen and the README named the report as `var/log/kingletas/process_guard.log`. The handler rotates daily, so that file never exists; both now name `process_guard-<date>.log`.

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
