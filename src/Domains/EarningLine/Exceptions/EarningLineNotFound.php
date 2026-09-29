<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Exceptions;

use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class EarningLineNotFound extends RuntimeException
{
    public function __construct(Uuid $id)
    {
        parent::__construct(sprintf('Earning line "%s" was not found.', $id->toRfc4122()));
    }
}
