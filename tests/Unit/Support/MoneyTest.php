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

it('adds and subtracts without changing the original', function () {
    $a = Money::of('0.500000000');
    $b = Money::of('0.123456789');

    expect($a->add($b)->toDecimal())->toBe('0.623456789')
        ->and($a->subtract($b)->toDecimal())->toBe('0.376543211')
        ->and($b->subtract($a)->toDecimal())->toBe('-0.376543211')
        ->and($a->toDecimal())->toBe('0.500000000')
        ->and($a->negate()->toDecimal())->toBe('-0.500000000');
});

it('compares amounts', function () {
    $small = Money::of('0.01');
    $large = Money::of('0.02');

    expect($small->compareTo($large))->toBe(-1)
        ->and($large->compareTo($small))->toBe(1)
        ->and($small->compareTo(Money::ofNanos(10_000_000)))->toBe(0)
        ->and($small->equals(Money::of('0.010')))->toBeTrue()
        ->and($small->isLessThan($large))->toBeTrue()
        ->and($large->isGreaterThan($small))->toBeTrue()
        ->and($small->isGreaterThanOrEqualTo($small))->toBeTrue()
        ->and($small->isLessThanOrEqualTo($small))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::of('-0.01')->isNegative())->toBeTrue()
        ->and($small->isPositive())->toBeTrue()
        ->and(Money::max($small, $large))->toEqual($large)
        ->and(Money::min($small, $large))->toEqual($small);
});

it('sums a list of amounts', function () {
    expect(Money::sum([Money::of('0.1'), Money::of('0.2'), Money::of('0.3')])->toDecimal())->toBe('0.600000000')
        ->and(Money::sum([])->isZero())->toBeTrue();
});

it('multiplies by a ratio, rounding up to the next nanodollar', function () {
    expect(Money::ofNanos(100)->multiplyRatio(125, 100)->nanos)->toBe(125)
        ->and(Money::ofNanos(1)->multiplyRatio(125, 100)->nanos)->toBe(2)
        ->and(Money::ofNanos(3)->multiplyRatio(1, 3)->nanos)->toBe(1)
        ->and(Money::ofNanos(4)->multiplyRatio(1, 3)->nanos)->toBe(2)
        ->and(Money::zero()->multiplyRatio(125, 100)->nanos)->toBe(0)
        ->and(Money::of('0.373875')->multiplyRatio(125, 100)->toDecimal())->toBe('0.467343750');
});

it('rounds a negative ratio towards positive infinity', function () {
    expect(Money::ofNanos(-1)->multiplyRatio(125, 100)->nanos)->toBe(-1);
});

it('multiplies large amounts without overflowing the intermediate product', function () {
    // $5 per 1M tokens × 8,000,000 tokens / 1M = $40
    expect(Money::of('5.00')->multiplyRatio(8_000_000, 1_000_000)->toDecimal())->toBe('40.000000000')
        ->and(Money::of('9000000.00')->multiplyRatio(1_000_000_000, 1_000_000_000)->toDecimal())->toBe('9000000.000000000');
});

it('rejects a zero or negative denominator', function () {
    Money::of('1')->multiplyRatio(1, 0);
})->throws(InvalidArgumentException::class);

it('formats for display with four decimals, or six below one cent, without changing the stored value', function () {
    $amount = Money::of('0.046125000');

    expect($amount->format())->toBe('$0.0461')
        ->and(Money::of('0.000575')->format())->toBe('$0.000575')
        ->and(Money::of('0.00000049')->format())->toBe('$0.000000')
        ->and(Money::of('0.00999995')->format())->toBe('$0.010000')
        ->and(Money::of('12.34565')->format())->toBe('$12.3457')
        ->and(Money::of('1.00')->format())->toBe('$1.0000')
        ->and(Money::of('-0.25')->format())->toBe('-$0.2500')
        ->and(Money::zero()->format())->toBe('$0.0000')
        ->and($amount->toDecimal())->toBe('0.046125000');
});

it('serialises to its exact decimal string', function () {
    expect(json_encode(['price' => Money::of('0.046125')]))->toBe('{"price":"0.046125000"}')
        ->and((string) Money::of('1.5'))->toBe('1.500000000');
});
