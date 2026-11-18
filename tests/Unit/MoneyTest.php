<?php

use App\Support\Money;

it('parses provider decimal strings into nano-dollars', function (string $usd, int $nano) {
    expect(Money::fromDecimal($usd))->toBe($nano);
})->with([
    ['0.169080000', 169_080_000],
    ['0.373875000', 373_875_000],
    ['2.00', 2_000_000_000],
    ['0', 0],
    ['0.000000001', 1],
    ['1.0000000000', 1_000_000_000], // trailing zeros beyond nine decimals are fine
]);

it('refuses amounts finer than a nano-dollar', function () {
    Money::fromDecimal('0.0000000001');
})->throws(InvalidArgumentException::class);

it('renders nano-dollars as decimal strings', function () {
    expect(Money::toDecimal(169_080_000))->toBe('0.169080000')
        ->and(Money::toDecimal(86_431_250, 4))->toBe('0.0864')
        ->and(Money::toDecimal(2_000_000_000, 2))->toBe('2.00');
});

it('formats for display', function () {
    expect(Money::format(86_431_250))->toBe('$0.0864')
        ->and(Money::format(-86_431_250))->toBe('-$0.0864')
        ->and(Money::format(2_000_000_000, 2))->toBe('$2.00');
});

it('applies a 25% markup exactly', function () {
    expect(Money::applyMarkup(169_080_000, 2500))->toBe(211_350_000)
        ->and(Money::applyMarkup(373_875_000, 2500))->toBe(467_343_750)
        ->and(Money::applyMarkup(0, 2500))->toBe(0);
});

it('rounds a markup up to the next nano-dollar when it is not exact', function () {
    expect(Money::applyMarkup(1, 2500))->toBe(2)     // 1.25 -> 2
        ->and(Money::applyMarkup(4, 2500))->toBe(5); // exact
});

it('rejects a negative amount for markup', function () {
    Money::applyMarkup(-1, 2500);
})->throws(InvalidArgumentException::class);
