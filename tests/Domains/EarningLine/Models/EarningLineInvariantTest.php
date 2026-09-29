<?php

declare(strict_types=1);

namespace Tests\Domains\EarningLine\Models;

use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\ValueObjects\Comment;
use DateTimeImmutable;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\MoneyTestHelper;

/**
 * Guards the invariants that no single SQL CHECK constraint can express because
 * they span the earning_lines and earning_line_corrections tables (or, here, the
 * in-memory aggregate and its correction history).
 */
final class EarningLineInvariantTest extends TestCase
{
    use MoneyTestHelper;

    public function testAfterAmountEqualsFrozenBasePlusRunningSumOfAdjustments(): void
    {
        $line = $this->buildLineWithFiveCorrections();

        $base = $line->frozenSystemAmount();
        self::assertNotNull($base);

        $running = $base;
        foreach ($line->corrections() as $correction) {
            $running = $running->add($correction->adjustment);
            self::assertTrue(
                $running->equals($correction->afterAmount),
                "after_amount mismatch at sequence {$correction->sequence}",
            );
        }
    }

    public function testCurrentAmountEqualsFrozenBasePlusSumOfAllAdjustmentsAndLastAfterAmount(): void
    {
        $line = $this->buildLineWithFiveCorrections();

        $base = $line->frozenSystemAmount();
        self::assertNotNull($base);

        $sum = array_reduce(
            $line->corrections(),
            static fn (Money $carry, $correction): Money => $carry->add($correction->adjustment),
            $base,
        );

        self::assertTrue($line->currentAmount()->equals($sum));

        $corrections = $line->corrections();
        $last = $corrections[count($corrections) - 1];
        self::assertTrue($line->currentAmount()->equals($last->afterAmount));
    }

    public function testFrozenSystemAmountIsNullIfAndOnlyIfTheLineHasNoCorrections(): void
    {
        $freshLine = EarningLine::calculate(
            id: Uuid::v7(),
            employeeId: 1,
            description: 'Base salary',
            amount: self::usd('1000.00'),
            calculatedAt: new DateTimeImmutable(),
        );
        self::assertNull($freshLine->frozenSystemAmount());
        self::assertSame([], $freshLine->corrections());

        $correctedLine = $this->buildLineWithFiveCorrections();
        self::assertNotNull($correctedLine->frozenSystemAmount());
        self::assertNotSame([], $correctedLine->corrections());
    }

    private function buildLineWithFiveCorrections(): EarningLine
    {
        $line = EarningLine::calculate(
            id: Uuid::v7(),
            employeeId: 1,
            description: 'Base salary',
            amount: self::usd('1000.00'),
            calculatedAt: new DateTimeImmutable(),
        );
        $line->recalculate(self::usd('1050.00'));

        $line->addCorrection(self::usd('-45.55'), new Comment('a'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('100.10'), new Comment('b'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('-0.10'), new Comment('c'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('-0.20'), new Comment('d'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('0.20'), new Comment('e'), 1, new DateTimeImmutable());

        return $line;
    }
}
