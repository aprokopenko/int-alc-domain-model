<?php

declare(strict_types=1);

namespace App\Demo\Listeners;

use App\Domains\EarningLine\Events\EarningLineRecalculationIgnored;

/**
 * Makes step 4 of the business case observable: an automatic recalculation that is
 * silently a no-op in the domain still produces a visible audit line here, so a
 * specialist watching logs can see that source data changed but was correctly
 * ignored, rather than seeing nothing happen at all.
 */
final class IgnoredRecalculationLogger
{
    public function __invoke(EarningLineRecalculationIgnored $event): void
    {
        printf(
            "recalculation of line %s to %s %s ignored — line already has a manual correction (current value: %s %s)\n",
            $event->lineId->toRfc4122(),
            $event->rejectedAmount->getCurrency()->getCode(),
            $this->format($event->rejectedAmount->getAmount()),
            $event->currentAmount->getCurrency()->getCode(),
            $this->format($event->currentAmount->getAmount()),
        );
    }

    private function format(string $minorUnits): string
    {
        return bcdiv($minorUnits, '100', 2);
    }
}
