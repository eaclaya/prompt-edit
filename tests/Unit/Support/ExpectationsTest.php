<?php

use App\Support\Money;

it('compares Money, nanodollar ints and decimal strings with toEqualMoney', function () {
    expect(Money::of('0.373875'))->toEqualMoney('0.373875')
        ->and(Money::of('0.373875'))->toEqualMoney('0.373875000')
        ->and(373_875_000)->toEqualMoney('0.373875')
        ->and('0.373875000')->toEqualMoney(Money::ofNanos(373_875_000))
        ->and(Money::of('0.1'))->not->toEqualMoney('0.100000001');
});

it('explains a toEqualMoney mismatch in dollars', function () {
    expect(Money::of('0.1'))->toEqualMoney('0.2');
})->throws(PHPUnit\Framework\ExpectationFailedException::class, 'Expected 0.100000000 to equal 0.200000000');
