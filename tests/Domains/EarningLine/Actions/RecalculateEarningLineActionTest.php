<?php

declare(strict_types=1);

namespace Tests\Domains\EarningLine\Actions;

use App\Domains\EarningLine\Actions\AddManualCorrectionAction;
use App\Domains\EarningLine\Actions\RecalculateEarningLineAction;
use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Events\EarningLineRecalculated;
use App\Domains\EarningLine\Events\EarningLineRecalculationIgnored;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\RecalculationOutcome;
use App\Domains\EarningLine\ValueObjects\AddManualCorrectionData;
use App\Domains\EarningLine\ValueObjects\RecalculateEarningLineData;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Tests\MoneyTestHelper;
use Tests\TestCase;

final class RecalculateEarningLineActionTest extends TestCase
{
    use MoneyTestHelper;

    public function testItAppliesRecalculationAndDispatchesEarningLineRecalculatedWhenNotYetCorrected(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $repository->save($line);

        $dispatched = [];
        $this->container->get(EventDispatcherInterface::class)->addListener(
            EarningLineRecalculated::class,
            function (EarningLineRecalculated $event) use (&$dispatched): void {
                $dispatched[] = $event;
            },
        );

        $outcome = $this->container->get(RecalculateEarningLineAction::class)->execute(
            new RecalculateEarningLineData($id, self::usd('1050.00')),
        );

        self::assertSame(RecalculationOutcome::Applied, $outcome);
        self::assertTrue($repository->get($id)->currentAmount()->equals(self::usd('1050.00')));
        self::assertCount(1, $dispatched);
    }

    public function testItIgnoresRecalculationAndDispatchesRecalculationIgnoredOnceCorrected(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $repository->save($line);

        $this->container->get(AddManualCorrectionAction::class)->execute(new AddManualCorrectionData(
            lineId: $id,
            adjustment: self::usd('-45.55'),
            comment: 'Reversing deduction',
            createdBy: 7,
            createdAt: new DateTimeImmutable(),
        ));

        $dispatched = [];
        $this->container->get(EventDispatcherInterface::class)->addListener(
            EarningLineRecalculationIgnored::class,
            function (EarningLineRecalculationIgnored $event) use (&$dispatched): void {
                $dispatched[] = $event;
            },
        );

        $outcome = $this->container->get(RecalculateEarningLineAction::class)->execute(
            new RecalculateEarningLineData($id, self::usd('1120.00')),
        );

        self::assertSame(RecalculationOutcome::IgnoredLineFrozen, $outcome);
        self::assertTrue($repository->get($id)->currentAmount()->equals(self::usd('954.45')));
        self::assertCount(1, $dispatched);
    }
}
