<?php

use App\Pricing\BasisPointsMarkup;
use App\Support\Money;

it('adds 25% to the provider cost', function () {
    expect((new BasisPointsMarkup(2500))->apply(Money::of('0.305510')))->toEqualMoney('0.3818875');
});

it('rounds a fractional nanodollar up, in our favour', function () {
    // 1 nano × 1.25 = 1.25 → 2; 3 nanos × 1.25 = 3.75 → 4
    expect((new BasisPointsMarkup(2500))->apply(Money::ofNanos(1)))->toEqualMoney(2)
        ->and((new BasisPointsMarkup(2500))->apply(Money::ofNanos(3)))->toEqualMoney(4);
});

it('keeps zero at zero', function () {
    expect((new BasisPointsMarkup(2500))->apply(Money::zero()))->toEqualMoney(0);
});

it('refuses a negative markup', function () {
    new BasisPointsMarkup(-1);
})->throws(InvalidArgumentException::class, 'must not be negative');
