<?php

declare(strict_types=1);

namespace App\Domains\EarningLine;

use App\Domains\EarningLine\Exceptions\EarningLineNotFound;
use App\Domains\EarningLine\Models\EarningLine;
use Symfony\Component\Uid\Uuid;

interface EarningLineRepository
{
    /**
     * @throws EarningLineNotFound
     */
    public function get(Uuid $id): EarningLine;

    public function save(EarningLine $line): void;
}
