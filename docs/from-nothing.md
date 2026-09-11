# From nothing to a working module-process-guard

> [!warning] This is a starting point, not a guide
> It was scaffolded so the file exists and its shape is right. The install step
> below is real; **everything under it still has to be written**, and until it is
> this page helps nobody. If you are reading it as a visitor rather than as the
> maintainer, that is the answer to why it is thin.

By the end of this, somebody who has never seen module-process-guard should have it doing
something real.

## Contents

- [What this is](#what-this-is)
- [Step 1: install it](#step-1-install-it)
- [Step 2: point it at something](#step-2-point-it-at-something)
- [Step 3: run it](#step-3-run-it)
- [What you get for free](#what-you-get-for-free)
- [Where to go next](#where-to-go-next)

## What this is

Budgets and kill switches for the hot paths: times every observer on a guarded event, contains the ones declared advisory, sheds them when a path blows its budget, and reports what it saw. Order placement, totals collection, catalogue saves and queue consumers.

Say what problem that solves, in the reader's own terms, before any mechanics.

## Step 1: install it

```bash
composer require kingletas/module-process-guard
```

## Step 2: point it at something

What does it need to be told before it can do anything? Name the one setting
that matters most, and what happens when it is missing.

## Step 3: run it

Show real output, copied from a real run. If a healthy run says nothing, say so
plainly — that surprises people.

## What you get for free

The things a reader would otherwise build themselves, and did not have to.

## Where to go next

- [README](../README.md)
- [CONTRIBUTING.md](../CONTRIBUTING.md)
