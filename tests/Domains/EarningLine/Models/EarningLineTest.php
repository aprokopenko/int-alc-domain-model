<?php

declare(strict_types=1);

namespace Tests\Domains\EarningLine\Models;

use App\Domains\EarningLine\Exceptions\ZeroAdjustmentNotAllowed;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\RecalculationOutcome;
use App\Domains\EarningLine\ValueObjects\Comment;
use DateTimeImmutable;
use Money\Currency;
use Money\Exception\CurrencyMismatchException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\MoneyTestHelper;

final class EarningLineTest extends TestCase
{
    use MoneyTestHelper;

    private function freshLine(): EarningLine
    {
        return EarningLine::calculate(
            id: Uuid::v7(),
            employeeId: 1,
            description: 'Base salary',
            amount: self::usd('1000.00'),
            calculatedAt: new DateTimeImmutable(),
        );
    }

    public function testRecalculationAppliesBeforeAnyCorrection(): void
    {
        $line = $this->freshLine();

        $outcome = $line->recalculate(self::usd('1050.00'));

        self::assertSame(RecalculationOutcome::Applied, $outcome);
        self::assertTrue($line->currentAmount()->equals(self::usd('1050.00')));
        self::assertFalse($line->isFrozen());
        self::assertNull($line->frozenSystemAmount());
    }

    public function testRecalculationIsIgnoredOnceLineHasManualCorrection(): void
    {
        $line = $this->freshLine();
        $line->recalculate(self::usd('1050.00'));
        $line->addCorrection(self::usd('-45.55'), new Comment('Reversing deduction'), 1, new DateTimeImmutable());

        $outcome = $line->recalculate(self::usd('1120.00'));

        self::assertSame(RecalculationOutcome::IgnoredLineFrozen, $outcome);
        self::assertTrue($line->currentAmount()->equals(self::usd('1004.45')));
        self::assertTrue($line->frozenSystemAmount()->equals(self::usd('1050.00')));
    }

    public function testFirstCorrectionSnapshotsTheFrozenAmountAndLaterOnesDoNotOverwriteIt(): void
    {
        $line = $this->freshLine();
        $line->recalculate(self::usd('1050.00'));

        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        self::assertTrue($line->frozenSystemAmount()->equals(self::usd('1050.00')));

        $line->addCorrection(self::usd('100.10'), new Comment('Second'), 1, new DateTimeImmutable());
        self::assertTrue($line->frozenSystemAmount()->equals(self::usd('1050.00')));
    }

    public function testZeroAdjustmentIsRejected(): void
    {
        $line = $this->freshLine();

        $this->expectException(ZeroAdjustmentNotAllowed::class);

        $line->addCorrection(self::usd('0.00'), new Comment('No-op'), 1, new DateTimeImmutable());
    }

    public function testMismatchedCurrencyIsRejected(): void
    {
        $line = $this->freshLine();

        $this->expectException(CurrencyMismatchException::class);

        $line->addCorrection(
            new \Money\Money(-100, new Currency('EUR')),
            new Comment('Wrong currency'),
            1,
            new DateTimeImmutable(),
        );
    }

    public function testSequenceIncrementsFromOne(): void
    {
        $line = $this->freshLine();

        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('100.10'), new Comment('Second'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('-0.10'), new Comment('Third'), 1, new DateTimeImmutable());

        $sequences = array_map(static fn ($c) => $c->sequence, $line->corrections());

        self::assertSame([1, 2, 3], $sequences);
    }

    public function testReturnedCorrectionsArrayCannotBeMutatedByTheCaller(): void
    {
        $line = $this->freshLine();
        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());

        $corrections = $line->corrections();
        $corrections[] = $corrections[0];

        self::assertCount(1, $line->corrections());
    }
}
