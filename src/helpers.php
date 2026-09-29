<?php

declare(strict_types=1);

namespace App;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

/**
 * Parses a decimal string (e.g. "1050.00") into a Money value in minor units.
 */
function parseAmount(string $decimal, string $currencyCode = 'USD'): Money
{
    static $parser = null;
    $parser ??= new DecimalMoneyParser(new ISOCurrencies());

    return $parser->parse($decimal, new Currency($currencyCode));
}

/**
 * Formats a Money value as a signed, currency-symbol-prefixed decimal string,
 * e.g. Money(-4555, USD) -> "-$45.55".
 */
function formatMoney(Money $money): string
{
    $decimal = bcdiv($money->getAmount(), '100', 2);
    $negative = str_starts_with($decimal, '-');

    return ($negative ? '-' : '') . '$' . number_format((float) ltrim($decimal, '-'), 2);
}
