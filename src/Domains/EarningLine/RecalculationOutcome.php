<?php

declare(strict_types=1);

namespace App\Domains\EarningLine;

enum RecalculationOutcome
{
    /** No prior manual correction existed; the new system amount was applied. */
    case Applied;

    /** A manual correction already exists; the line is frozen and the new amount was ignored. */
    case IgnoredLineFrozen;
}
