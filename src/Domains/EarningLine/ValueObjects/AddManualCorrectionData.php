<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\ValueObjects;

use DateTimeImmutable;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final readonly class AddManualCorrectionData
{
    public function __construct(
        public Uuid $lineId,
        public Money $adjustment,
        public string $comment,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
