<?php

declare(strict_types=1);

use App\Domains\EarningLine\Actions\AddManualCorrectionAction;
use App\Domains\EarningLine\Actions\RecalculateEarningLineAction;
use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\ValueObjects\AddManualCorrectionData;
use App\Domains\EarningLine\ValueObjects\RecalculateEarningLineData;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

use function App\bootContainer;
use function App\container;
use function App\formatMoney;
use function App\parseAmount;

require __DIR__ . '/../vendor/autoload.php';

// A fresh file on every run keeps the demo deterministic and idempotent to re-run.
$dbDir = __DIR__ . '/../runtime';
if (! is_dir($dbDir)) {
    mkdir($dbDir);
}

$dbPath = $dbDir . '/demo.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

bootContainer('sqlite:' . $dbPath);

// From here on, container() resolves the same instance from anywhere — no
// $container variable needs to be passed around or kept in scope.
$repository = container()->get(EarningLineRepository::class);
$dispatcher = container()->get(EventDispatcherInterface::class);
$addManualCorrection = container()->get(AddManualCorrectionAction::class);
$recalculateEarningLine = container()->get(RecalculateEarningLineAction::class);

/** @var list<array{step: int, event: string, amount: string, comment: string, value: string}> */
$log = [];

// Step 1 — the system calculates the line for the first time.
$line = EarningLine::calculate(
    id: Uuid::v7(),
    employeeId: 4821,
    description: 'Base salary',
    amount: parseAmount('1000.00'),
    calculatedAt: new DateTimeImmutable(),
);
$lineId = $line->id();

$repository->save($line);
foreach ($line->releaseEvents() as $event) {
    $dispatcher->dispatch($event);
}
$log[] = [1, 'System calculates the line', '—', '—', formatMoney($repository->get($lineId)->currentAmount())];

// Step 2 — source data changes; no manual correction exists yet, so recalculation applies.
$recalculateEarningLine->execute(new RecalculateEarningLineData($lineId, parseAmount('1050.00')));
$log[] = [2, 'Source data changes, system recalculates', '—', '(no manual correction yet, so this is allowed)', formatMoney($repository->get($lineId)->currentAmount())];

// Step 3 — the specialist manually corrects the line for the first time.
$addManualCorrection->execute(new AddManualCorrectionData(
    lineId: $lineId,
    adjustment: parseAmount('-45.55'),
    comment: 'Employee declined dental benefit; reversing deduction',
    createdBy: 501,
    createdAt: new DateTimeImmutable(),
));
$log[] = [3, 'Specialist adds a manual correction', '-$45.55', 'Employee declined dental benefit; reversing deduction', formatMoney($repository->get($lineId)->currentAmount())];

// Step 4 — source data changes again; the line is now frozen, so this is ignored.
// TASK.md does not specify a figure for this attempt; $1,120.00 is used for the demo.
$recalculateEarningLine->execute(new RecalculateEarningLineData($lineId, parseAmount('1120.00')));
$log[] = [4, 'Source data changes again, system attempts to recalculate', '—', 'must be ignored — line already has a manual correction', formatMoney($repository->get($lineId)->currentAmount())];

// Step 5 — a second correction.
$addManualCorrection->execute(new AddManualCorrectionData(
    lineId: $lineId,
    adjustment: parseAmount('100.10'),
    comment: 'Late correction: missed approved overtime bonus',
    createdBy: 501,
    createdAt: new DateTimeImmutable(),
));
$log[] = [5, 'Specialist adds a second correction', '+$100.10', 'Late correction: missed approved overtime bonus', formatMoney($repository->get($lineId)->currentAmount())];

// Step 6 — a third correction.
$addManualCorrection->execute(new AddManualCorrectionData(
    lineId: $lineId,
    adjustment: parseAmount('-0.10'),
    comment: 'Minor rounding adjustment',
    createdBy: 501,
    createdAt: new DateTimeImmutable(),
));
$log[] = [6, 'Specialist adds a third correction', '-$0.10', 'Minor rounding adjustment', formatMoney($repository->get($lineId)->currentAmount())];

// Step 7 — a fourth correction, later realized to be a mistake.
$addManualCorrection->execute(new AddManualCorrectionData(
    lineId: $lineId,
    adjustment: parseAmount('-0.20'),
    comment: 'Second minor rounding adjustment',
    createdBy: 501,
    createdAt: new DateTimeImmutable(),
));
$log[] = [7, 'Specialist adds a fourth correction', '-$0.20', 'Second minor rounding adjustment', formatMoney($repository->get($lineId)->currentAmount())];

// Step 8 — a compensating correction fixing step 7's mistake. Nothing is edited or
// deleted; the fix is a new, fifth correction that is fully visible in the history.
$addManualCorrection->execute(new AddManualCorrectionData(
    lineId: $lineId,
    adjustment: parseAmount('0.20'),
    comment: 'Correcting mistake in adjustment #4',
    createdBy: 501,
    createdAt: new DateTimeImmutable(),
));
$log[] = [8, 'Specialist adds a compensating correction, realizing step 7 was a mistake', '+$0.20', 'Correcting mistake in adjustment #4', formatMoney($repository->get($lineId)->currentAmount())];

echo "Step-by-step\n";
echo "============\n";
foreach ($log as [$step, $event, $stepAmount, $comment, $value]) {
    printf("%d. %-70s %10s  %s\n   -> current value: %s\n", $step, $event, $stepAmount, $comment, $value);
}

$final = $repository->get($lineId);

echo "\nFinal audit history\n";
echo "====================\n";
printf("%-45s %12s\n", 'System value (frozen at first correction)', formatMoney($final->frozenSystemAmount() ?? $final->currentAmount()));
foreach ($final->corrections() as $correction) {
    $sign = $correction->adjustment->isPositive() ? '+' : '';
    printf(
        "%-45s %12s\n",
        sprintf('Adjustment %d: %s', $correction->sequence, $correction->comment->toString()),
        $sign . formatMoney($correction->adjustment),
    );
}
printf("%-45s %12s\n", 'Current (new) value', formatMoney($final->currentAmount()));
