<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Events;

use App\Domains\EarningLine\Models\Correction;
use App\Domains\Shared\DomainEvent;
use Symfony\Component\Uid\Uuid;

final readonly class CorrectionAdded implements DomainEvent
{
    public function __construct(
        public Uuid $lineId,
        public Correction $correction,
    ) {
    }
}
