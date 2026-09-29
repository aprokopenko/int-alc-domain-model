<?php

declare(strict_types=1);

namespace App\Demo\Persistence;

use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Exceptions\EarningLineNotFound;
use App\Domains\EarningLine\Models\Correction;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\ValueObjects\Comment;
use DateTimeImmutable;
use Money\Currency;
use Money\Money;
use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class SqliteEarningLineRepository implements EarningLineRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(Uuid $id): EarningLine
    {
        $stmt = $this->pdo->prepare('SELECT * FROM earning_lines WHERE id = :id');
        $stmt->execute(['id' => $id->toRfc4122()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new EarningLineNotFound($id);
        }

        $currency = new Currency($row['currency']);

        $correctionsStmt = $this->pdo->prepare(
            'SELECT * FROM earning_line_corrections WHERE line_id = :id ORDER BY sequence ASC',
        );
        $correctionsStmt->execute(['id' => $id->toRfc4122()]);

        $corrections = array_map(
            static fn (array $correctionRow): Correction => new Correction(
                sequence: (int) $correctionRow['sequence'],
                adjustment: new Money((int) $correctionRow['adjustment_amount'], new Currency($correctionRow['currency'])),
                comment: new Comment($correctionRow['comment']),
                afterAmount: new Money((int) $correctionRow['after_amount'], new Currency($correctionRow['currency'])),
                createdBy: (int) $correctionRow['created_by'],
                createdAt: new DateTimeImmutable($correctionRow['created_at']),
            ),
            $correctionsStmt->fetchAll(PDO::FETCH_ASSOC),
        );

        return EarningLine::restore(
            id: $id,
            employeeId: (int) $row['employee_id'],
            description: $row['description'],
            calculatedAt: new DateTimeImmutable($row['calculated_at']),
            currentAmount: new Money((int) $row['current_amount'], $currency),
            frozenSystemAmount: $row['frozen_system_amount'] !== null
                ? new Money((int) $row['frozen_system_amount'], $currency)
                : null,
            corrections: $corrections,
        );
    }

    public function save(EarningLine $line): void
    {
        $id = $line->id()->toRfc4122();
        $currency = $line->currentAmount()->getCurrency()->getCode();

        $upsertLine = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO earning_lines
                (id, employee_id, description, currency, current_amount, frozen_system_amount, calculated_at)
            VALUES
                (:id, :employee_id, :description, :currency, :current_amount, :frozen_system_amount, :calculated_at)
            ON CONFLICT (id) DO UPDATE SET
                current_amount = excluded.current_amount,
                frozen_system_amount = excluded.frozen_system_amount
            SQL,
        );
        $upsertLine->execute([
            'id' => $id,
            'employee_id' => $line->employeeId(),
            'description' => $line->description(),
            'currency' => $currency,
            'current_amount' => (int) $line->currentAmount()->getAmount(),
            'frozen_system_amount' => $line->frozenSystemAmount()?->getAmount(),
            'calculated_at' => $line->calculatedAt()->format(DATE_ATOM),
        ]);

        // Corrections are append-only in the domain and the storage triggers reject
        // UPDATE/DELETE outright, so only sequences beyond what is already stored are
        // ever inserted — re-persisting an already-saved correction would abort.
        $persistedMax = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sequence), 0) FROM earning_line_corrections WHERE line_id = :id',
        );
        $persistedMax->execute(['id' => $id]);
        $persistedSequence = (int) $persistedMax->fetchColumn();

        $insertCorrection = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO earning_line_corrections
                (line_id, sequence, adjustment_amount, after_amount, currency, comment, created_by, created_at)
            VALUES
                (:line_id, :sequence, :adjustment_amount, :after_amount, :currency, :comment, :created_by, :created_at)
            SQL,
        );

        foreach ($line->corrections() as $correction) {
            if ($correction->sequence <= $persistedSequence) {
                continue;
            }

            $insertCorrection->execute([
                'line_id' => $id,
                'sequence' => $correction->sequence,
                'adjustment_amount' => (int) $correction->adjustment->getAmount(),
                'after_amount' => (int) $correction->afterAmount->getAmount(),
                'currency' => $correction->adjustment->getCurrency()->getCode(),
                'comment' => $correction->comment->toString(),
                'created_by' => $correction->createdBy,
                'created_at' => $correction->createdAt->format(DATE_ATOM),
            ]);
        }
    }
}
