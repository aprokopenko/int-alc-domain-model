# History of Manual Adjustments to an Earning Line

A proof-of-concept domain model for some payroll platform: an earning line that the
system calculates automatically, that a payroll specialist can manually correct, and whose
correction history is always visible, traceable, and permanent. See [`TASK.md`](TASK.md) for
the business case and [`PLAN.md`](PLAN.md) for the design notes this was built from.

## How to run it

Requires PHP 8.2+ with `ext-bcmath` and `ext-pdo_sqlite`, and Composer.
(Tested on PHP 8.5 installation of Arch Linux)

```bash
composer install

# Unit + acceptance tests
./vendor/bin/phpunit

# A CLI walkthrough of TASK.md's worked example against a real (file-backed) SQLite DB
php bin/demo.php
```

`bin/demo.php` replays all 8 steps of the task's worked example and prints the step-by-step
running value and the final audit history — the numbers match TASK.md's tables exactly.
`tests/TaskScenarioTest.php` asserts the same thing as an automated acceptance test.

## Model walkthrough

The domain lives in `src/Domains/EarningLine/`. `EarningLine` is the aggregate root and the
only place business rules are enforced:

- **`EarningLine::calculate(...)`** — named constructor for a freshly system-calculated line.
- **`recalculate(Money $newAmount)`** — the system's automatic recalculation. Returns a
  `RecalculationOutcome` (`Applied` or `IgnoredLineFrozen`) and never throws: an automatic
  recalculation attempt on an already-corrected line (step 4 of the task) is expected,
  routine traffic, not an error condition.
- **`addCorrection(Money $adjustment, Comment $comment, int $createdBy, DateTimeImmutable $createdAt)`**
  — the specialist's manual correction. On the *first* call ever made on a line, it snapshots
  the line's current value into `$frozenSystemAmount`. From that point on, `recalculate()`
  is permanently a no-op — the freeze, not a flag the specialist sets, is what "corrections
  take permanent precedence" means in code.
- **`corrections(): Correction[]`** — an append-only, ordered history. `Correction` is a
  `readonly` class with no setters, constructed only by the aggregate or by the repository's
  rehydration path; nothing in this codebase can edit or remove one once created. "If the
  specialist made a mistake, they add a new correction that fixes the previous one" (step 8
  in the task) is not a special case — it's just another `addCorrection()` call.

`EarningLine` and `Correction` live under `Models/`. Two small value objects round this out,
under `ValueObjects/`: `Comment` (trims and rejects blank — mandatory by construction, not by a
guard clause repeated at every call site) and `RecalculationOutcome` (an enum, so callers can't
mistake "ignored" for an exception they forgot to catch).

Application-level use cases are one Action class each, under `Domains/EarningLine/Actions/`
(`AddManualCorrectionAction`, `RecalculateEarningLineAction`), each taking its own input value
object from `ValueObjects/` (`AddManualCorrectionData`, `RecalculateEarningLineData`): load the
aggregate, call the one domain method that matters, persist, then dispatch whatever events the
aggregate recorded.

`src/boot.php` is the composition root. `App\bootContainer(string $dsn): DI\Container` builds a
[PHP-DI](https://php-di.org/) container wiring a PDO SQLite connection, the repository, and a
PSR-14 event dispatcher behind a single DSN parameter, so `bin/demo.php` passes a file path and
the tests pass `:memory:`; it also registers the container as the process-wide instance. Actions
and the repository aren't built by hand-written factory methods — they're autowired from their
constructors' type hints, so any class the container doesn't have an explicit rule for (both
Actions included) is resolved automatically. `App\container(): DI\Container`, modeled on Laravel's
`app()` helper, hands back that same instance from anywhere with no argument, so code doesn't need
a `$container` variable threaded through it:

```php
App\bootContainer('sqlite:' . $dbPath); // once, at the entry point

// anywhere after that — no $container in scope required
App\container()->get(AddManualCorrectionAction::class)->execute($data);
```

`src/helpers.php` holds two small, framework-free functions used wherever `bin/demo.php` and the
tests need to turn a decimal string into `Money` or back: `parseAmount()` and `formatMoney()`.
Both files are registered under Composer's `autoload.files`, so `vendor/autoload.php` alone makes
`App\bootContainer()`, `App\container()`, `App\parseAmount()` and `App\formatMoney()` available
everywhere.

One listener is registered by default: `IgnoredRecalculationLogger` reacts to
`EarningLineRecalculationIgnored` and prints an audit line, so an ignored recalculation is *visible*
output rather than silence — you can see it in `bin/demo.php`'s step 4.

## Why a state-based aggregate, not Event Sourcing

The task explicitly allows either. Event Sourcing is a good fit when the append-only log
*is* the domain (which is arguably true here — corrections already are an append-only log by
requirement) and when replay, temporal queries, or independent read models earn their cost.
None of that is needed for a payroll line: the business only ever asks "what's the value now"
and "how did we get here", and a normal table plus an append-only child table answers both
without an event store, snapshotting strategy, or upcasting story. Event Sourcing was used
where the requirement already forces it — corrections themselves are stored as an immutable,
ordered, append-only log, enforced by both `readonly` PHP objects and SQLite triggers that
reject `UPDATE`/`DELETE` outright — without adopting it as the persistence model for the whole
aggregate.

Domain events are still recorded and dispatched (`EarningLineCalculated`,
`EarningLineRecalculated`, `EarningLineRecalculationIgnored`, `CorrectionAdded`) so the design isn't
locked out of an event-driven future (an audit log listener, a read-model projector), but they
are notifications *about* state changes, not the source of truth for them.

## Deliberate denormalization

`current_amount` (on `earning_lines`) and `after_amount` (on each correction row) are stored,
not computed on read. Both are:

- **computed only inside the aggregate** — never a constructor or command parameter — so no
  caller can persist a value inconsistent with the adjustment that produced it, and
- **guarded by `tests/Domains/EarningLine/EarningLineInvariantTest.php`**, which checks both
  against a fold over the correction history, because no single SQL `CHECK` can express an
  invariant that spans two tables.

Each correction stores `after_amount` rather than `before_amount` on purpose: `after(N)` plus
the frozen base reconstructs every `before(N)` (`before(N) = after(N-1)`, and `before(1)` is
the frozen base itself), while storing `before` would leave the *final* value unreachable
without an extra synthetic "closing" row.

`sequence`, not `created_at`, defines correction order. Timestamps are not a total order —
clock skew and same-millisecond writes are real — so `sequence` is a plain integer, unique per
line, monotonically increasing from 1, assigned by the aggregate itself.

## Assumptions

- **Zero-amount corrections are rejected.** A correction that doesn't change the value isn't
  a correction; `ZeroAdjustmentNotAllowed` is thrown before it reaches storage, and a `CHECK`
  constraint backs it up at the storage layer too.
- **Corrections are never reordered.** `sequence` is assigned once, at creation, by the
  aggregate, and is immutable thereafter.
- **`created_by` was added.** The task doesn't ask for it, but "the full audit history of
  corrections" reads as incomplete without recording *who* made each one, so every correction
  carries a `created_by` (an integer user id — no user/auth model exists in this exercise).
- **Single currency per line, enforced by the `Money` library itself.** A correction in a
  currency that doesn't match the line's throws `Money\Exception\CurrencyMismatchException` —
  free correctness from `moneyphp/money` rather than a hand-rolled check.
- **No authorization/roles.** Who is *allowed* to add a correction is out of scope; `createdBy`
  records identity, not permission.

## Out of scope

- **Concurrency.** Two specialists correcting the same line at once isn't handled — there's no
  optimistic locking (a `version` column and a compare-and-swap on save) or pessimistic
  locking. For a real system this would be needed before go-live.
- **A UI, a framework, an HTTP layer.** The task explicitly asks for a data model plus a CLI,
  not an application — see `bin/demo.php` and the test suite instead.
- **Multi-currency lines, tax/withholding logic, approval workflows.** Not part of the stated
  business case.
