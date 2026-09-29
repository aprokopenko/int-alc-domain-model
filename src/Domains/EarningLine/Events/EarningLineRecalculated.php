<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Events;

use App\Domains\Shared\DomainEvent;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final readonly class EarningLineRecalculated implements DomainEvent
{
    public function __construct(
        public Uuid $lineId,
        public Money $previousAmount,
        public Money $newAmount,
    ) {
    }
}
