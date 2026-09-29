<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Models;

use App\Domains\EarningLine\ValueObjects\Comment;
use DateTimeImmutable;
use Money\Money;

/**
 * One immutable, traceable manual adjustment applied to an earning line.
 *
 * Constructed only by EarningLine (append-only, via addCorrection()) or by the
 * repository's rehydration path. There are no setters: once created, a Correction
 * cannot be changed, matching the "never edited or silently deleted" business rule.
 */
final readonly class Correction
{
    public function __construct(
        public int $sequence,
        public Money $adjustment,
        public Comment $comment,
        public Money $afterAmount,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
