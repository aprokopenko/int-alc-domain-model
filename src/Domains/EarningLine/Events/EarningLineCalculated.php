<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Events;

use App\Domains\Shared\DomainEvent;
use DateTimeImmutable;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final readonly class EarningLineCalculated implements DomainEvent
{
    public function __construct(
        public Uuid $lineId,
        public Money $amount,
        public DateTimeImmutable $calculatedAt,
    ) {
    }
}
