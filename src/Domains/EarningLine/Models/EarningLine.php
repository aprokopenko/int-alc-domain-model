<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Models;

use App\Domains\EarningLine\Events\CorrectionAdded;
use App\Domains\EarningLine\Events\EarningLineCalculated;
use App\Domains\EarningLine\Events\EarningLineRecalculated;
use App\Domains\EarningLine\Events\EarningLineRecalculationIgnored;
use App\Domains\EarningLine\Exceptions\ZeroAdjustmentNotAllowed;
use App\Domains\EarningLine\RecalculationOutcome;
use App\Domains\EarningLine\ValueObjects\Comment;
use App\Domains\Shared\RecordsDomainEvents;
use DateTimeImmutable;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * An employee's earning line, with its automatic system value and the append-only
 * history of manual corrections that may override it.
 *
 * Core invariant: once a manual correction has ever been recorded, the system's
 * automatic recalculation permanently stops affecting the line's value, even if the
 * underlying source data changes again later. That first-correction moment is
 * captured by freezing the system amount into $frozenSystemAmount; from then on
 * recalculate() is a documented no-op rather than an error, because an automatic
 * recalculation attempt on a corrected line is expected, normal traffic.
 */
final class EarningLine
{
    use RecordsDomainEvents;

    /** @var Correction[] */
    private array $corrections = [];

    private function __construct(
        private readonly Uuid $id,
        private readonly int $employeeId,
        private readonly string $description,
        private readonly DateTimeImmutable $calculatedAt,
        private Money $currentAmount,
        private ?Money $frozenSystemAmount,
    ) {
    }

    public static function calculate(
        Uuid $id,
        int $employeeId,
        string $description,
        Money $amount,
        DateTimeImmutable $calculatedAt,
    ): self {
        $line = new self($id, $employeeId, $description, $calculatedAt, $amount, frozenSystemAmount: null);

        $line->record(new EarningLineCalculated($id, $amount, $calculatedAt));

        return $line;
    }

    /**
     * Rebuilds an EarningLine from previously persisted state. Records no events:
     * rehydration is not a business occurrence.
     *
     * @param Correction[] $corrections must already be ordered by sequence
     */
    public static function restore(
        Uuid $id,
        int $employeeId,
        string $description,
        DateTimeImmutable $calculatedAt,
        Money $currentAmount,
        ?Money $frozenSystemAmount,
        array $corrections,
    ): self {
        $line = new self($id, $employeeId, $description, $calculatedAt, $currentAmount, $frozenSystemAmount);
        $line->corrections = $corrections;

        return $line;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function employeeId(): int
    {
        return $this->employeeId;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function calculatedAt(): DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    public function currentAmount(): Money
    {
        return $this->currentAmount;
    }

    public function isFrozen(): bool
    {
        return $this->frozenSystemAmount !== null;
    }

    /**
     * The value corrections are layered on top of: the frozen system amount once
     * the line has been corrected, otherwise the live (still-recalculable) amount.
     */
    public function baseAmount(): Money
    {
        return $this->frozenSystemAmount ?? $this->currentAmount;
    }

    public function frozenSystemAmount(): ?Money
    {
        return $this->frozenSystemAmount;
    }

    /**
     * @return Correction[] a defensive copy; the caller cannot mutate this line's history
     */
    public function corrections(): array
    {
        return [...$this->corrections];
    }

    /**
     * Applies an automatic, system-calculated amount — unless the line has already
     * received a manual correction, in which case the specialist's corrections take
     * permanent precedence and the new figure is ignored.
     */
    public function recalculate(Money $newAmount): RecalculationOutcome
    {
        if ($this->isFrozen()) {
            $this->record(new EarningLineRecalculationIgnored($this->id, $newAmount, $this->currentAmount));

            return RecalculationOutcome::IgnoredLineFrozen;
        }

        $previousAmount = $this->currentAmount;
        $this->currentAmount = $newAmount;

        $this->record(new EarningLineRecalculated($this->id, $previousAmount, $newAmount));

        return RecalculationOutcome::Applied;
    }

    /**
     * Records a manual correction. The first correction ever made on a line freezes
     * its current amount as the permanent base that all corrections are layered on;
     * subsequent corrections layer on that same frozen base. Mismatched currencies
     * fail via Money's own CurrencyMismatchException.
     */
    public function addCorrection(
        Money $adjustment,
        Comment $comment,
        int $createdBy,
        DateTimeImmutable $createdAt,
    ): void {
        if ($adjustment->isZero()) {
            throw new ZeroAdjustmentNotAllowed();
        }

        if ($this->frozenSystemAmount === null) {
            $this->frozenSystemAmount = $this->currentAmount;
        }

        $afterAmount = $this->currentAmount->add($adjustment);

        $correction = new Correction(
            sequence: count($this->corrections) + 1,
            adjustment: $adjustment,
            comment: $comment,
            afterAmount: $afterAmount,
            createdBy: $createdBy,
            createdAt: $createdAt,
        );

        $this->corrections[] = $correction;
        $this->currentAmount = $afterAmount;

        $this->record(new CorrectionAdded($this->id, $correction));
    }
}
