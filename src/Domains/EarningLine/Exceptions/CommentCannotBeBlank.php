<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Exceptions;

use InvalidArgumentException;

final class CommentCannotBeBlank extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A correction comment must not be blank.');
    }
}
