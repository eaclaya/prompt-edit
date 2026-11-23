<?php

use App\Support\Money;

it('parses a nine-decimal string to nanodollars and formats it back exactly', function () {
    $money = Money::of('0.046125000');

    expect($money->nanos)->toBe(46_125_000)
        ->and($money->toDecimal())->toBe('0.046125000');
});
