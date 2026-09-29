<?php

declare(strict_types=1);

namespace Tests\Demo\Persistence;

use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Exceptions\EarningLineNotFound;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\ValueObjects\Comment;
use DateTimeImmutable;
use PDO;
use PDOException;
use Symfony\Component\Uid\Uuid;
use Tests\MoneyTestHelper;
use Tests\TestCase;

final class SqliteEarningLineRepositoryTest extends TestCase
{
    use MoneyTestHelper;

    public function testRoundTripPreservesOrderAmountsCurrencyAndTheFrozenValue(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $line->recalculate(self::usd('1050.00'));
        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('100.10'), new Comment('Second'), 1, new DateTimeImmutable());
        $repository->save($line);

        $reloaded = $repository->get($id);

        self::assertTrue($reloaded->currentAmount()->equals(self::usd('1104.55')));
        self::assertTrue($reloaded->frozenSystemAmount()->equals(self::usd('1050.00')));
        self::assertSame('USD', $reloaded->currentAmount()->getCurrency()->getCode());

        $sequences = array_map(static fn ($c) => $c->sequence, $reloaded->corrections());
        self::assertSame([1, 2], $sequences);

        $comments = array_map(static fn ($c) => $c->comment->toString(), $reloaded->corrections());
        self::assertSame(['First', 'Second'], $comments);
    }

    public function testRehydratedLineStillSatisfiesTheAmountInvariants(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        $line->addCorrection(self::usd('100.10'), new Comment('Second'), 1, new DateTimeImmutable());
        $repository->save($line);

        $reloaded = $repository->get($id);

        $base = $reloaded->frozenSystemAmount();
        self::assertNotNull($base);

        $running = $base;
        foreach ($reloaded->corrections() as $correction) {
            $running = $running->add($correction->adjustment);
            self::assertTrue($running->equals($correction->afterAmount));
        }
        self::assertTrue($reloaded->currentAmount()->equals($running));
    }

    public function testUnknownLineThrows(): void
    {
        $this->expectException(EarningLineNotFound::class);

        $this->container->get(EarningLineRepository::class)->get(Uuid::v7());
    }

    public function testUpdatingStoredCorrectionAborts(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        $repository->save($line);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Corrections are immutable');

        $this->container->get(PDO::class)->exec(
            "UPDATE earning_line_corrections SET adjustment_amount = -1 WHERE line_id = '{$id->toRfc4122()}' AND sequence = 1",
        );
    }

    public function testDeletingStoredCorrectionAborts(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $line->addCorrection(self::usd('-45.55'), new Comment('First'), 1, new DateTimeImmutable());
        $repository->save($line);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Corrections cannot be deleted');

        $this->container->get(PDO::class)->exec(
            "DELETE FROM earning_line_corrections WHERE line_id = '{$id->toRfc4122()}' AND sequence = 1",
        );
    }

    public function testBlankCommentIsRejectedAtTheStorageLayer(): void
    {
        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $this->container->get(EarningLineRepository::class)->save($line);

        $this->expectException(PDOException::class);

        $this->container->get(PDO::class)->exec(
            <<<SQL
            INSERT INTO earning_line_corrections
                (line_id, sequence, adjustment_amount, after_amount, currency, comment, created_by, created_at)
            VALUES
                ('{$id->toRfc4122()}', 1, -100, 900, 'USD', '   ', 1, '2024-01-01T00:00:00+00:00')
            SQL,
        );
    }

    public function testZeroAdjustmentIsRejectedAtTheStorageLayer(): void
    {
        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $this->container->get(EarningLineRepository::class)->save($line);

        $this->expectException(PDOException::class);

        $this->container->get(PDO::class)->exec(
            <<<SQL
            INSERT INTO earning_line_corrections
                (line_id, sequence, adjustment_amount, after_amount, currency, comment, created_by, created_at)
            VALUES
                ('{$id->toRfc4122()}', 1, 0, 1000, 'USD', 'Valid comment', 1, '2024-01-01T00:00:00+00:00')
            SQL,
        );
    }
}
