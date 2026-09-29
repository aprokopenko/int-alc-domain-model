-- Amounts are integers in minor units (e.g. 105000 = $1,050.00), matching what
-- moneyphp/money exchanges natively via Money::getAmount(). Never floats: floating
-- point cannot represent currency exactly and this domain is about exact cents.
--
-- frozen_system_amount IS NOT NULL means the line has received at least one manual
-- correction: automatic recalculation is a no-op for it from that point on.
--
-- current_amount is denormalized: it always equals
--   COALESCE(frozen_system_amount, <amount before any correction>) + SUM(adjustment_amount)
-- and equals the last correction's after_amount when corrections exist. This is
-- guarded by the invariant test, not by a SQL CHECK, because it spans two tables.
CREATE TABLE IF NOT EXISTS earning_lines (
    id                   TEXT    NOT NULL PRIMARY KEY, -- UUIDv7, canonical 36-char form
    employee_id          INTEGER NOT NULL,
    description          TEXT    NOT NULL,
    currency             TEXT    NOT NULL,
    current_amount       INTEGER NOT NULL,
    frozen_system_amount INTEGER,                      -- NULL until the first correction
    calculated_at        TEXT    NOT NULL              -- ISO-8601
) STRICT;

-- sequence, not created_at, defines correction order: timestamps are not a total
-- order (clock skew, same-millisecond writes). after_amount is stored rather than
-- folded on read so the line's history needs no running SUM to reconstruct any
-- point in time, and "after" (not "before") because after(N) plus the frozen base
-- reconstructs every before(N), while the reverse would leave the final value
-- unreachable without a synthetic "closing" row.
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

-- "No correction may ever be edited or silently deleted once saved" is a storage
-- guarantee, not just an application-level convention: readonly PHP objects stop
-- this process's code from mutating a Correction, but only these triggers stop a
-- stray UPDATE/DELETE statement (a script, a future maintainer, a DBA console).
CREATE TRIGGER IF NOT EXISTS corrections_immutable BEFORE UPDATE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections are immutable'); END;

CREATE TRIGGER IF NOT EXISTS corrections_undeletable BEFORE DELETE ON earning_line_corrections
BEGIN SELECT RAISE(ABORT, 'Corrections cannot be deleted'); END;
