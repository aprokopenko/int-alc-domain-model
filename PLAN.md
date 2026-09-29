# Plan

## Approach

Plain PHP domain model, no framework and no ORM. All business rules are invariants of a single
aggregate (`EarningLine`). Persistence is a port (`EarningLineRepository`) with one SQLite adapter,
wired by a PHP-DI container.

## Stack

| Concern | Choice | Note |
|---|---|---|
| Package manager | Composer | `composer.json` declares `ext-bcmath` and `ext-pdo_sqlite` |
| Money | `moneyphp/money` ^4.9 | needs `ext-bcmath` |
| Identity | `symfony/uid` ^8.1 | `Uuid::v7()` |
| Events | `psr/event-dispatcher` ^1.0 + `symfony/event-dispatcher` ^8.1 | Symfony's dispatcher implements the PSR-14 interface |
| Storage | SQLite via PDO | integer minor units, never floats |
| DI container | `php-di/php-di` ^7.1 | autowired by constructor type hints, configured in `src/boot.php` |
| Tests | PHPUnit ^12 | `testdox` output |
| Static analysis | PHPStan level max | skip if time is short |
| Autoload | PSR-4, `App\` → `src/` | |

`Money` and `Uuid` are used directly in domain signatures — no wrappers. Currency-mismatch
enforcement comes free from `Money\Exception\CurrencyMismatchException`.

## Domain model

### `EarningLine` (aggregate root)

State:

- `Uuid $id` — UUIDv7, generated in the domain
- `int $employeeId`
- `string $description` — e.g. "Base salary"
- `Money $currentAmount` — the line's value right now
- `?Money $frozenSystemAmount` — system value captured at the first correction; `null` = not frozen
- `Correction[] $corrections` — append-only, ordered by `sequence`

Behaviour:

- `EarningLine::calculate(...)` — named constructor, sets `$currentAmount`, records `EarningLineCalculated`
- `EarningLine::restore(...)` — named constructor for rehydrating persisted state; records no events
- `isFrozen(): bool` — `$this->frozenSystemAmount !== null`
- `baseAmount(): Money` — `$this->frozenSystemAmount ?? $this->currentAmount`
- `recalculate(Money $newAmount): RecalculationOutcome` — returns an enum, never throws. `Applied`
  overwrites `$currentAmount` and records `EarningLineRecalculated`; `IgnoredLineFrozen` changes
  nothing and records `EarningLineRecalculationIgnored` carrying the rejected figure
- `addCorrection(Money $adjustment, Comment $comment, int $createdBy, DateTimeImmutable $createdAt): void`
  — on the first call snapshots `$currentAmount` into `$frozenSystemAmount`; appends a `Correction`
  with the next `sequence` and its computed `afterAmount`; updates `$currentAmount`; records
  `CorrectionAdded`
- `currentAmount(): Money`
- `corrections(): array` — returns a copy; callers cannot mutate the collection
- `releaseEvents(): array` — returns and clears recorded `DomainEvent`s

### `Correction` — `final readonly class`

`int $sequence`, `Money $adjustment`, `Comment $comment`, `Money $afterAmount`, `int $createdBy`,
`DateTimeImmutable $createdAt`. No setters; constructed only by the aggregate or by the repository's
rehydration path.

### `Comment` — `final readonly class`

Trims and rejects empty. Constructed by the Action path and by repository rehydration.

### Ordering and denormalization

- **`sequence` defines order**, not `created_at`. Per line, monotonic from 1, unique on
  `(line_id, sequence)`.
- **`after_amount` and `current_amount` are stored**, not folded. Both are computed inside the
  aggregate and are never constructor or Action-input parameters. The invariant test checks both
  against the fold.
- **`after`, not `before`**, is stored on each correction row.

### Rules enforced, and where

| Rule | Enforced by |
|---|---|
| Comment mandatory | `Comment` constructor + `CHECK (length(trim(comment)) > 0)` |
| Adjustment must be non-zero | `EarningLine::addCorrection()` + `CHECK (adjustment_amount <> 0)` |
| Adjustment currency matches line | `moneyphp/money` `CurrencyMismatchException` |
| Corrections immutable / undeletable | `readonly` classes + SQLite `BEFORE UPDATE` / `BEFORE DELETE` triggers with `RAISE(ABORT, ...)` |
| Recalculation ignored once corrected | `frozen_system_amount IS NOT NULL` → early return in `recalculate()` |

## Layout

Plural folders for collections of a kind, singular for the aggregate's own module folder.

```
database/
  schema.sql
src/
  Domains/
    EarningLine/
      Models/
        EarningLine.php                 # aggregate root
        Correction.php
      ValueObjects/
        Comment.php
        AddManualCorrectionData.php     # Action input DTO
        RecalculateEarningLineData.php  # Action input DTO
      Actions/
        AddManualCorrectionAction.php
        RecalculateEarningLineAction.php
      RecalculationOutcome.php          # enum { Applied, IgnoredLineFrozen }
      EarningLineRepository.php         # port
      Events/{EarningLineCalculated,EarningLineRecalculated,EarningLineRecalculationIgnored,CorrectionAdded}.php
      Exceptions/{ZeroAdjustmentNotAllowed,EarningLineNotFound,CommentCannotBeBlank}.php
    Shared/
      DomainEvent.php                   # marker interface, types releaseEvents()
      RecordsDomainEvents.php           # trait
  Demo/
    Persistence/SqliteEarningLineRepository.php
    Listeners/IgnoredRecalculationLogger.php
  boot.php                              # App\bootContainer(string $dsn), App\container()
  helpers.php                           # App\parseAmount(), App\formatMoney()
bin/demo.php
tests/
```

Each Action class takes one `ValueObjects/` input DTO, loads the aggregate through
`EarningLineRepository`, calls the one aggregate method that matters, persists, then dispatches
whatever events the aggregate recorded.

`src/boot.php` defines two functions:

- `App\bootContainer(string $dsn): DI\Container` — builds a PHP-DI container wiring `PDO`
  (applies `PRAGMA foreign_keys = ON`, loads `database/schema.sql`), `EarningLineRepository`
  (autowired to `SqliteEarningLineRepository`), and `EventDispatcherInterface` (a Symfony
  dispatcher with `IgnoredRecalculationLogger` registered on `EarningLineRecalculationIgnored`).
  Registers the built container as the process-wide instance. `bin/demo.php` passes a file DSN;
  tests pass `:memory:`.
- `App\container(): DI\Container` — returns the process-wide container from anywhere, no argument
  needed. Throws if called before `bootContainer()`.

`src/helpers.php` defines `App\parseAmount(string $decimal, string $currencyCode = 'USD'): Money`
and `App\formatMoney(Money $money): string`, used by `bin/demo.php` and `tests/MoneyTestHelper.php`.
Both `boot.php` and `helpers.php` are registered under composer's `autoload.files`.

No in-memory repository: tests use SQLite `:memory:`, fresh per test.

## Events

Dispatch happens in the Action, after `save()` returns:

```
load aggregate → call domain method → repository->save() → foreach releaseEvents() as $e: dispatch($e)
```

## Schema

Amounts are integers in **minor units** (cents for USD), matching what `moneyphp/money` exchanges
via `getAmount()`.

```sql
CREATE TABLE IF NOT EXISTS earning_lines (
    id                   TEXT    NOT NULL PRIMARY KEY, -- UUIDv7, canonical 36-char form
    employee_id          INTEGER NOT NULL,
    description          TEXT    NOT NULL,
    currency             TEXT    NOT NULL,
    current_amount       INTEGER NOT NULL,
    frozen_system_amount INTEGER,                      -- NULL until the first correction
    calculated_at        TEXT    NOT NULL              -- ISO-8601
) STRICT;

CREATE TABLE IF NOT EXISTS earning_line_corrections (
    line_id           TEXT    NOT NULL REFERENCES earning_lines(id),
    sequence          INTEGER NOT NULL,
    adjustment_amount INTEGER NOT NULL,
    after_amount      INTEGER NOT NULL,           -- line value after this correction
    currency          TEXT    NOT NULL,
    comment           TEXT    NOT NULL,
    created_by        INTEGER NOT NULL,
    created_at        TEXT    NOT NULL,
    PRIMARY KEY (line_id, sequence),
    CHECK (adjustment_amount <> 0),
    CHECK (length(trim(comment)) > 0)
) STRICT;

CREATE TRIGGER IF NOT EXISTS corrections_immutable BEFORE UPDATE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections are immutable'); END;

CREATE TRIGGER IF NOT EXISTS corrections_undeletable BEFORE DELETE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections cannot be deleted'); END;
```

`created_by` is not required by the task; an audit trail should record who made each correction.

## Tests

- **`TaskScenarioTest`** — replays all 8 steps, asserts the value after each, then asserts the final
  audit output matches the expected table exactly. The acceptance test.
- **`EarningLineTest`** (no DB) — recalculation applies before the first correction; is ignored
  after, leaving `currentAmount` and `frozenSystemAmount` untouched; the first correction snapshots
  the frozen amount and later ones do not overwrite it; zero adjustment rejected; mismatched
  currency rejected; `sequence` increments from 1; the returned corrections array cannot be mutated
  by the caller.
- **`CommentTest`** (no DB) — blank/whitespace comment rejected.
- **`EarningLineInvariantTest`** — guards the denormalized amounts, which no SQL `CHECK` can express:
  - `after_amount == baseAmount + sum(adjustments 1..N)` for every row
  - `current_amount == baseAmount + sum(all adjustments)`, and equals the last row's `after_amount`
  - `frozen_system_amount IS NULL` ⟺ the line has no corrections
- **`SqliteEarningLineRepositoryTest`** (`:memory:`) — round-trip preserves order, amounts, currency
  and the frozen value, and a rehydrated line still satisfies the invariants; `UPDATE` on a stored
  correction aborts; `DELETE` aborts; the `CHECK`s reject a blank comment and a zero adjustment.
- **Action tests** — `AddManualCorrectionActionTest`, `RecalculateEarningLineActionTest`,
  asserting dispatched events.
- **`tests/TestCase.php`** — base class building the container via `App\bootContainer('sqlite::memory:')`.

## `bin/demo.php`

Runs the 8 steps against a file-backed SQLite database and prints both tables from `TASK.md`: the
step-by-step running value and the final audit history. The task gives no figure for step 4's
attempted recalculation; the demo uses $1,120.00.

## README (deliverable)

- how to run, and a model walkthrough
- why a state-based aggregate rather than Event Sourcing
- the deliberate denormalization of `current_amount` and `after_amount`, and how it is guarded
- assumptions: zero adjustments rejected; corrections never reordered; `created_by` added; step 4's
  attempted figure invented for the demo
- out of scope: **concurrency** — two specialists correcting simultaneously would need optimistic
  locking (a `version` column and a compare-and-swap on save)
