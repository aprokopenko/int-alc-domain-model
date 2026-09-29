<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Events;

use App\Domains\Shared\DomainEvent;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Raised when an automatic recalculation is attempted on a line that already has
 * at least one manual correction. The line is frozen; the rejected figure is
 * carried purely for audit/observability purposes and never applied.
 */
final readonly class EarningLineRecalculationIgnored implements DomainEvent
{
    public function __construct(
        public Uuid $lineId,
        public Money $rejectedAmount,
        public Money $currentAmount,
    ) {
    }
}
