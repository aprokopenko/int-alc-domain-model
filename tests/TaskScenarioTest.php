<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\EarningLine\Actions\AddManualCorrectionAction;
use App\Domains\EarningLine\Actions\RecalculateEarningLineAction;
use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\RecalculationOutcome;
use App\Domains\EarningLine\ValueObjects\AddManualCorrectionData;
use App\Domains\EarningLine\ValueObjects\RecalculateEarningLineData;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Replays every step of TASK.md's worked example and asserts both the running
 * value after each step and the final audit history exactly match the task's
 * expected tables. This is the acceptance test for the whole domain model.
 */
final class TaskScenarioTest extends TestCase
{
    use MoneyTestHelper;

    public function testTheExampleWorkflowProducesTheExpectedNumbers(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);
        $addManualCorrection = $this->container->get(AddManualCorrectionAction::class);
        $recalculateEarningLine = $this->container->get(RecalculateEarningLineAction::class);

        $id = Uuid::v7();

        // Step 1 — system calculates the line.
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $repository->save($line);
        self::assertLineValue('1000.00', $id);

        // Step 2 — source data changes, system recalculates (no correction yet: allowed).
        $outcome = $recalculateEarningLine->execute(
            new RecalculateEarningLineData($id, self::usd('1050.00')),
        );
        self::assertSame(RecalculationOutcome::Applied, $outcome);
        self::assertLineValue('1050.00', $id);

        // Step 3 — specialist adds a manual correction.
        $addManualCorrection->execute(new AddManualCorrectionData(
            $id,
            self::usd('-45.55'),
            'Employee declined dental benefit; reversing deduction',
            1,
            new DateTimeImmutable(),
        ));
        self::assertLineValue('1004.45', $id);

        // Step 4 — source data changes again; must be ignored, line already corrected.
        $outcome = $recalculateEarningLine->execute(
            new RecalculateEarningLineData($id, self::usd('1120.00')),
        );
        self::assertSame(RecalculationOutcome::IgnoredLineFrozen, $outcome);
        self::assertLineValue('1004.45', $id);

        // Step 5 — specialist adds a second correction.
        $addManualCorrection->execute(new AddManualCorrectionData(
            $id,
            self::usd('100.10'),
            'Late correction: missed approved overtime bonus',
            1,
            new DateTimeImmutable(),
        ));
        self::assertLineValue('1104.55', $id);

        // Step 6 — specialist adds a third correction.
        $addManualCorrection->execute(new AddManualCorrectionData(
            $id,
            self::usd('-0.10'),
            'Minor rounding adjustment',
            1,
            new DateTimeImmutable(),
        ));
        self::assertLineValue('1104.45', $id);

        // Step 7 — specialist adds a fourth correction.
        $addManualCorrection->execute(new AddManualCorrectionData(
            $id,
            self::usd('-0.20'),
            'Second minor rounding adjustment',
            1,
            new DateTimeImmutable(),
        ));
        self::assertLineValue('1104.25', $id);

        // Step 8 — specialist adds a compensating correction fixing step 7's mistake.
        $addManualCorrection->execute(new AddManualCorrectionData(
            $id,
            self::usd('0.20'),
            'Correcting mistake in adjustment #4',
            1,
            new DateTimeImmutable(),
        ));
        self::assertLineValue('1104.45', $id);

        $final = $repository->get($id);

        self::assertTrue($final->frozenSystemAmount()->equals(self::usd('1050.00')));

        $adjustments = array_map(static fn ($c) => $c->adjustment, $final->corrections());
        self::assertCount(5, $adjustments);
        self::assertTrue($adjustments[0]->equals(self::usd('-45.55')));
        self::assertTrue($adjustments[1]->equals(self::usd('100.10')));
        self::assertTrue($adjustments[2]->equals(self::usd('-0.10')));
        self::assertTrue($adjustments[3]->equals(self::usd('-0.20')));
        self::assertTrue($adjustments[4]->equals(self::usd('0.20')));

        self::assertTrue($final->currentAmount()->equals(self::usd('1104.45')));
    }

    private function assertLineValue(string $expectedDecimal, Uuid $id): void
    {
        self::assertTrue(
            $this->container->get(EarningLineRepository::class)->get($id)->currentAmount()->equals(self::usd($expectedDecimal)),
            "Expected line value to be {$expectedDecimal}",
        );
    }
}
