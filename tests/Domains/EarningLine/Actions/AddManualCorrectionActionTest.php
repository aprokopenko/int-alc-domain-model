<?php

declare(strict_types=1);

namespace Tests\Domains\EarningLine\Actions;

use App\Domains\EarningLine\Actions\AddManualCorrectionAction;
use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\Events\CorrectionAdded;
use App\Domains\EarningLine\Models\EarningLine;
use App\Domains\EarningLine\ValueObjects\AddManualCorrectionData;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Tests\MoneyTestHelper;
use Tests\TestCase;

final class AddManualCorrectionActionTest extends TestCase
{
    use MoneyTestHelper;

    public function testItPersistsTheCorrectionAndDispatchesCorrectionAdded(): void
    {
        $repository = $this->container->get(EarningLineRepository::class);

        $id = Uuid::v7();
        $line = EarningLine::calculate($id, 42, 'Base salary', self::usd('1000.00'), new DateTimeImmutable());
        $repository->save($line);

        $dispatched = [];
        $this->container->get(EventDispatcherInterface::class)->addListener(
            CorrectionAdded::class,
            function (CorrectionAdded $event) use (&$dispatched): void {
                $dispatched[] = $event;
            },
        );

        $this->container->get(AddManualCorrectionAction::class)->execute(new AddManualCorrectionData(
            lineId: $id,
            adjustment: self::usd('-45.55'),
            comment: 'Employee declined dental benefit; reversing deduction',
            createdBy: 7,
            createdAt: new DateTimeImmutable(),
        ));

        $reloaded = $repository->get($id);
        self::assertTrue($reloaded->currentAmount()->equals(self::usd('954.45')));
        self::assertTrue($reloaded->frozenSystemAmount()->equals(self::usd('1000.00')));

        self::assertCount(1, $dispatched);
        self::assertTrue($dispatched[0]->correction->adjustment->equals(self::usd('-45.55')));
        self::assertSame(7, $dispatched[0]->correction->createdBy);
    }
}
