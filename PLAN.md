# Plan

## Approach

Plain PHP domain model, no framework and no ORM. All business rules are invariants of a single
aggregate (`EarningLine`). Persistence is a port (`EarningLineRepository`) with one SQLite adapter,
wired by a small factory.

## Stack

| Concern | Choice | Note |
|---|---|---|
| Package manager | Composer | `composer.json` declares `ext-bcmath` and `ext-pdo_sqlite` |
| Money | `moneyphp/money` ^4.9 | needs `ext-bcmath` |
| Identity | `symfony/uid` ^8.1 | `Uuid::v7()` |
| Events | `psr/event-dispatcher` ^1.0 + `symfony/event-dispatcher` ^8.1 | Symfony's dispatcher implements the PSR-14 interface |
| Storage | SQLite via PDO | integer minor units, never floats |
| Tests | PHPUnit ^12 | |
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
- `isFrozen(): bool` — `$this->frozenSystemAmount !== null`
- `baseAmount(): Money` — `$this->frozenSystemAmount ?? $this->currentAmount`
- `recalculate(Money $newAmount): RecalculationOutcome` — returns an enum, never throws (step 4 of
  the task is normal operation). `Applied` overwrites `$currentAmount` and records
  `EarningLineRecalculated`; `IgnoredLineFrozen` changes nothing and records `RecalculationIgnored`
  carrying the rejected figure
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

Trims and rejects empty. A type rather than a guard clause, so the command path and repository
rehydration are both covered by one constructor.

### Ordering and denormalization

- **`sequence` defines order**, not `created_at` — timestamps are not a total order. Per line,
  monotonic from 1, unique on `(line_id, sequence)`.
- **`after_amount` and `current_amount` are stored**, not folded: line totals need no join and the
  current value is a field read. Both are computed inside the aggregate and are never constructor or
  command parameters, so no caller can supply a value inconsistent with the adjustments. The
  invariant test checks both against the fold.
- **`after`, not `before`**: "before" of row N equals "after" of row N−1, so "after" plus the frozen
  base reconstructs every "before"; the reverse leaves the final value unreachable.

### Rules enforced, and where

| Rule | Enforced by |
|---|---|
| Comment mandatory | `Comment` constructor + `CHECK (length(trim(comment)) > 0)` |
| Adjustment must be non-zero | `EarningLine::addCorrection()` + `CHECK (adjustment_amount <> 0)` — `DecimalMoneyParser` silently rounds `0.001` to `$0.00` |
| Adjustment currency matches line | `moneyphp/money` `CurrencyMismatchException` |
| Corrections immutable / undeletable | `readonly` classes + SQLite `BEFORE UPDATE` / `BEFORE DELETE` triggers with `RAISE(ABORT, ...)` |
| Recalculation ignored once corrected | `frozen_system_amount IS NOT NULL` → early return in `recalculate()` |

`readonly` protects the object graph; the triggers make "never edited or silently deleted once
saved" true at the storage layer.

## Layout

Plural folders for collections of a kind, singular for the aggregate's own module folder.

```
src/
  Domains/
    EarningLine/
      EarningLine.php                   # aggregate root
      Correction.php
      Comment.php
      RecalculationOutcome.php          # enum { Applied, IgnoredLineFrozen }
      EarningLineRepository.php         # port
      Events/{EarningLineCalculated,EarningLineRecalculated,RecalculationIgnored,CorrectionAdded}.php
      Exceptions/{ZeroAdjustmentNotAllowed,EarningLineNotFound}.php
    Shared/
      DomainEvent.php                   # marker interface, types releaseEvents()
      RecordsDomainEvents.php           # trait
  Commands/
    AddManualCorrection.php             # command DTO
    AddManualCorrectionHandler.php
    RecalculateEarningLine.php
    RecalculateEarningLineHandler.php
  Infrastructure/
    ServiceFactory.php                  # composition root, takes a DSN
    Persistence/SqliteEarningLineRepository.php
    Persistence/schema.sql
    Listeners/IgnoredRecalculationLogger.php
bin/demo.php
tests/
```

`ServiceFactory` wires PDO → repository → dispatcher → handlers, with the DSN as a parameter:
`bin/demo.php` passes a file path, tests pass `:memory:`. It must set `PRAGMA foreign_keys = ON` on
every connection — SQLite defaults it to `0`, which makes `REFERENCES` decorative.

No in-memory repository: tests use SQLite `:memory:`, fresh per test.

## Events

Dispatch happens in the handler, after `save()` returns, so a listener can never act on state that
was not persisted:

```
load aggregate → call domain method → repository->save() → foreach releaseEvents() as $e: dispatch($e)
```

Handlers depend on `Psr\EventDispatcher\EventDispatcherInterface`; the concrete Symfony dispatcher is
wired in `ServiceFactory`.

One listener is registered: `IgnoredRecalculationLogger` reacts to `RecalculationIgnored` and prints
an audit line, so step 4 produces visible output ("recalculation to $1,120.00 ignored — line already
corrected") rather than silence.

## Schema

Amounts are integers in **minor units** (cents for USD), which is what `moneyphp/money` exchanges
natively via `getAmount()`.

```sql
-- Amounts are integers in minor units (e.g. 105000 = $1,050.00).
-- frozen_system_amount IS NOT NULL means the line has been manually corrected:
-- recalculate() is a no-op from that point on.
-- current_amount is denormalized: it always equals
--   COALESCE(frozen_system_amount, current_amount) + SUM(adjustment_amount).
CREATE TABLE earning_lines (
    id                   TEXT    NOT NULL PRIMARY KEY, -- UUIDv7, canonical 36-char form
    employee_id          INTEGER NOT NULL,
    description          TEXT    NOT NULL,
    currency             TEXT    NOT NULL,
    current_amount       INTEGER NOT NULL,
    frozen_system_amount INTEGER,                      -- NULL until the first correction
    calculated_at        TEXT    NOT NULL              -- ISO-8601
) STRICT;

CREATE TABLE earning_line_corrections (
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

CREATE TRIGGER corrections_immutable BEFORE UPDATE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections are immutable'); END;

CREATE TRIGGER corrections_undeletable BEFORE DELETE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections cannot be deleted'); END;
```

`created_by` is not required by the task; an audit trail should record who made each correction.

## Tests

- **`TaskScenarioTest`** — replays all 8 steps, asserts the value after each, then asserts the final
  audit output matches the expected table exactly. The acceptance test.
- **`EarningLineTest`** (no DB) — recalculation applies before the first correction; is ignored
  after, leaving `currentAmount` and `frozenSystemAmount` untouched; the first correction snapshots
  the frozen amount and later ones do not overwrite it; blank/whitespace comment rejected; zero
  adjustment rejected; mismatched currency rejected; `sequence` increments from 1; the returned
  corrections array cannot be mutated by the caller.
- **Invariant test** — guards the denormalized amounts, which no SQL `CHECK` can express:
  - `after_amount == baseAmount + sum(adjustments 1..N)` for every row
  - `current_amount == baseAmount + sum(all adjustments)`, and equals the last row's `after_amount`
  - `frozen_system_amount IS NULL` ⟺ the line has no corrections
- **`EarningLineRepositoryTest`** (`:memory:`) — round-trip preserves order, amounts, currency and
  the frozen value, and a rehydrated line still satisfies the invariants; `UPDATE` on a stored
  correction aborts; `DELETE` aborts; the `CHECK`s reject a blank comment and a zero adjustment.
- **Handler tests** — `AddManualCorrectionHandlerTest`, `RecalculateEarningLineHandlerTest`,
  asserting dispatched events.
- **`tests/TestCase.php`** — base class building the container from `ServiceFactory` against
  `:memory:`, so every test resolves the SQLite repository through normal DI.

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
