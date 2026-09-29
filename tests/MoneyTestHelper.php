<?php

declare(strict_types=1);

namespace Tests;

use Money\Money;

use function App\parseAmount;

trait MoneyTestHelper
{
    private static function usd(string $decimal): Money
    {
        return parseAmount($decimal, 'USD');
    }
}
