<?php

use App\Billing\DatabaseLedger;
use App\Contracts;
use App\Generations\SyncGeneration;
use App\Pricing\BasisPointsMarkup;
use App\Pricing\CachedPricingCatalog;
use App\Pricing\TokenCostCalculator;
use App\Pricing\TokenCostEstimator;
use Illuminate\Support\Facades\File;

const CONTRACT_IMPLEMENTATIONS = [
    Contracts\CostCalculator::class => TokenCostCalculator::class,
    Contracts\Markup::class => BasisPointsMarkup::class,
    Contracts\CostEstimator::class => TokenCostEstimator::class,
    Contracts\PricingCatalog::class => CachedPricingCatalog::class,
    Contracts\Ledger::class => DatabaseLedger::class,
    Contracts\GenerationSync::class => SyncGeneration::class,
];

dataset('contracts', fn () => collect(CONTRACT_IMPLEMENTATIONS)
    ->map(fn (string $implementation, string $contract) => [$contract, $implementation])
    ->all());

it('resolves each contract to its real implementation', function (string $contract, string $implementation) {
    expect(app($contract))->toBeInstanceOf($implementation);
})->with('contracts');

it('covers every interface in app/Contracts', function () {
    $interfaces = collect(File::files(app_path('Contracts')))
        ->map(fn (SplFileInfo $file) => 'App\\Contracts\\'.$file->getBasename('.php'))
        ->filter(fn (string $class) => interface_exists($class))
        ->values()
        ->all();

    expect($interfaces)->toEqualCanonicalizing(array_keys(CONTRACT_IMPLEMENTATIONS));
});

it('has no NotImplemented stubs left', function () {
    expect(File::isDirectory(app_path('Contracts/NotImplemented')))->toBeFalse()
        ->and(class_exists('App\\Contracts\\NotImplemented\\NotImplementedException'))->toBeFalse();
});
