<?php

use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;
use App\Contracts\Exceptions\InsufficientCredits;
use App\Enums\Quality;
use App\Enums\Size;
use App\Support\Money;

it('describes an estimate as provider and user ranges, serialised as decimal strings', function () {
    $estimate = new Estimate(
        providerMin: Money::of('0.2'), providerExpected: Money::of('0.3'), providerMax: Money::of('0.4'),
        userMin: Money::of('0.25'), userExpected: Money::of('0.375'), userMax: Money::of('0.5'),
    );

    expect($estimate->toArray())->toBe([
        'min' => '0.250000000',
        'expected' => '0.375000000',
        'max' => '0.500000000',
    ]);
});

it('counts prompt characters, not bytes, in an estimate input', function () {
    $input = new EstimateInput('Café ☕', Size::Square, Quality::Low, 2);

    expect($input->promptCharacters())->toBe(6)
        ->and($input->referenceImageCount)->toBe(2);
});

it('reports the shortfall when credits are insufficient', function () {
    $e = new InsufficientCredits(required: Money::of('0.5'), available: Money::of('0.2'));

    expect($e->required)->toEqualMoney('0.5')
        ->and($e->available)->toEqualMoney('0.2')
        ->and($e->shortfall())->toEqualMoney('0.3')
        ->and($e->getMessage())->toBe('Insufficient credits: $0.5000 required, $0.2000 available.');
});
