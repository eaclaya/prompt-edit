<?php

use App\Support\Money;

it('parses a nine-decimal string to nanodollars and formats it back exactly', function () {
    $money = Money::of('0.046125000');

    expect($money->nanos)->toBe(46_125_000)
        ->and($money->toDecimal())->toBe('0.046125000');
});

it('parses whole dollars, integers of nanos and negative amounts', function () {
    expect(Money::of('1')->nanos)->toBe(1_000_000_000)
        ->and(Money::of('1.00')->nanos)->toBe(1_000_000_000)
        ->and(Money::of('12.5')->nanos)->toBe(12_500_000_000)
        ->and(Money::of('-0.10')->nanos)->toBe(-100_000_000)
        ->and(Money::of('-0.10')->toDecimal())->toBe('-0.100000000')
        ->and(Money::ofNanos(46_125_000)->toDecimal())->toBe('0.046125000')
        ->and(Money::zero()->toDecimal())->toBe('0.000000000');
});

it('rejects floats', function () {
    Money::of(0.1);
})->throws(InvalidArgumentException::class, 'float');

it('rejects more than nine decimal places', function () {
    Money::of('0.0000000001');
})->throws(InvalidArgumentException::class);

it('rejects strings that are not decimal amounts', function (string $amount) {
    Money::of($amount);
})->throws(InvalidArgumentException::class)->with(['', 'abc', '1.2.3', '1e3', ' 1.00', '$1.00', '.5', '1.']);
