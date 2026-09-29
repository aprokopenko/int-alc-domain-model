<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\ValueObjects;

use App\Domains\EarningLine\Exceptions\CommentCannotBeBlank;

/**
 * A mandatory, human-authored explanation for a manual correction.
 *
 * Modeled as a type rather than a guard clause in the aggregate so that both the
 * command path and the repository's rehydration path are covered by one constructor:
 * it is impossible to construct a Correction with a blank comment from anywhere.
 */
final readonly class Comment
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new CommentCannotBeBlank();
        }

        $this->value = $trimmed;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
