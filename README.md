# Pest Plugin: Only Failed / Rerun Failed

[![CI](https://github.com/tim-zitcha/pest-plugin-rerun/actions/workflows/ci.yml/badge.svg)](https://github.com/tim-zitcha/pest-plugin-rerun/actions/workflows/ci.yml)

Adds two independent CLI flags to [Pest](https://pestphp.com) v4:

- `--only-failed` — skip straight to running only the tests that failed on
  the previous run, instead of the full suite.
- `--rerun-failed` — run the full suite as normal, and if anything failed,
  automatically rerun just those failed tests afterward, in the same
  invocation, under a distinct "Rerun of failed tests" section — so you can
  see at a glance whether a failure was consistent or flaky.

No `phpunit.xml` changes are required in consuming projects.

## Install

```bash
composer require --dev tim-zitcha/pest-plugin-rerun
```

Composer's plugin allow-list may prompt you to trust `pestphp/pest-plugin`
(the mechanism Pest itself uses to discover plugins from `composer.json`'s
`extra.pest.plugins`); this package is registered the same way.

## Usage

```bash
# Run everything, as normal, then automatically rerun anything that failed.
vendor/bin/pest --rerun-failed

# Skip straight to re-running only what failed last time.
vendor/bin/pest --only-failed

# Combine them: re-run only last run's failures, and if any of those still
# fail, rerun just those again in the same invocation.
vendor/bin/pest --only-failed --rerun-failed
```

Every run (with or without either flag) overwrites `.pest-only-failed.json`
in the project root with the current run's failures — a plain overwrite, not
a merge, so a test that's since been fixed drops off the list automatically.
Add this file to your `.gitignore`.

## The flake-exit-code decision

If `--rerun-failed` reruns a test and it **passes**, the overall run still
**exits non-zero**. The initial failure counts. The rerun section is purely
informational — it tells you whether a failure was consistent (fails again)
or possibly flaky (passes on rerun), but it does not launder the exit code.
This is a deliberate strict default: CI should not go green just because a
test happened to pass on a second attempt.

## `--parallel` is not supported

Both flags throw immediately if combined with `--parallel`. Each paratest
worker is a separate process with its own partial view of the results, so
detecting "what failed" and safely rewriting a single `.pest-only-failed.json`
isn't well-defined across workers. Rather than silently producing an
incomplete or wrong failure list, both flags refuse to run under `--parallel`.

## Known upgrade risks

This plugin relies on two internal PHPUnit/Pest details that are **not**
covered by either project's backward-compatibility promise:

- `PHPUnit\TestRunner\TestResult\Facade::result()` — PHPUnit's own internal
  accessor for the current run's results, marked `@internal`. A future
  PHPUnit release could change or remove it.
- Pest overrides PHPUnit's `--filter` matching (see
  `vendor/pestphp/pest/overrides/Runner/Filter/NameFilterIterator.php`) to
  match functional `test()`/`it()` cases against their **printable** name
  (the original description string, via `Pest\Contracts\HasPrintableTestCaseName`)
  rather than the internal, mangled method name PHPUnit's event system
  reports. This plugin builds its failure ids the same way Pest's override
  does, so `--filter` round-trips correctly — but that override is itself
  `@internal` to Pest and could change in a future release. Classic
  class-based (`extends TestCase`) tests aren't affected by this distinction.

If either of these break, both flags will most likely fail to select the
right tests (or select none) rather than fail silently — bug reports welcome.

## How it works

- `--only-failed` reads `.pest-only-failed.json` and, if it has entries,
  pushes a `--filter` argument onto the CLI arguments before PHPUnit boots
  (via `Pest\Contracts\Plugins\HandlesArguments`).
- After the run finishes (via `Pest\Contracts\Plugins\Terminable`), the
  plugin reads the final result, writes the current failure list, and — if
  `--rerun-failed` was passed and something failed — spawns a **fresh
  subprocess** (`php vendor/bin/pest --filter=... --colors=...`) scoped to
  just those tests. A second in-process PHPUnit run isn't attempted:
  `PHPUnit\TextUI\Application::run()` seals the event subscriber system with
  no corresponding unseal, so a second in-process run risks colliding with
  already-sealed state from the first pass.
