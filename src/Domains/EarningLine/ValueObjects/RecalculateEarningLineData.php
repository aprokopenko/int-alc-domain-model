<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\ValueObjects;

use Money\Money;
use Symfony\Component\Uid\Uuid;

final readonly class RecalculateEarningLineData
{
    public function __construct(
        public Uuid $lineId,
        public Money $newAmount,
    ) {
    }
}
