<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Exceptions;

use InvalidArgumentException;

final class ZeroAdjustmentNotAllowed extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A manual correction must adjust the line by a non-zero amount.');
    }
}
