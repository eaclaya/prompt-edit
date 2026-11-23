<?php

use App\Contracts\CostCalculator;
use App\Contracts\CostEstimator;
use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;
use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\GenerationSync;
use App\Contracts\Markup;
use App\Contracts\NotImplemented\NotImplementedException;
use App\Contracts\PricingCatalog;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\Data\ProviderGeneration;
use App\Support\Money;
use Tests\Fixtures\PixelforgeFixtures;

it('resolves every contract to a stub that says who implements it', function (string $contract, string $session, Closure $call) {
    $implementation = app($contract);

    expect($implementation)->toBeInstanceOf($contract)
        ->and(fn () => $call($implementation))->toThrow(NotImplementedException::class, "{$contract} is not implemented yet ({$session})");
})->with([
    'CostCalculator' => [CostCalculator::class, 'S2', fn (CostCalculator $c) => $c->providerCost(new Usage(1, 0, 0, 0), Rates::fromArray(config('pixelforge.pricing.rates')))],
    'Markup' => [Markup::class, 'S2', fn (Markup $m) => $m->apply(Money::of('1'))],
    'CostEstimator' => [CostEstimator::class, 'S2', fn (CostEstimator $e) => $e->estimate(new EstimateInput('A cat', Size::Square, Quality::Low, 0))],
    'PricingCatalog' => [PricingCatalog::class, 'S2', fn (PricingCatalog $p) => $p->current()],
    'GenerationSync' => [GenerationSync::class, 'S5', fn (GenerationSync $s) => $s->apply(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued')))],
]);

it('lets a later binding replace the stub', function () {
    app()->bind(Markup::class, fn () => new class implements Markup
    {
        public function apply(Money $providerCost): Money
        {
            return $providerCost->multiplyRatio(125, 100);
        }
    });

    expect(app(Markup::class)->apply(Money::of('0.1')))->toEqualMoney('0.125');
});

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
