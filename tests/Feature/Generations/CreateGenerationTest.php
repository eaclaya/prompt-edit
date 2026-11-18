<?php

use App\Billing\InsufficientCredits;
use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\CreateGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response(pixelforgePricingResponse())]);
});

it('records the generation, holds the maximum price and queues the submission', function () {
    $user = fundedUser();

    $generation = app(CreateGeneration::class)($user, str_repeat('a', 100), Size::Square, Quality::Medium, []);

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->est_price_min_nano)->toBe(58_256_250)
        ->and($generation->est_price_max_nano)->toBe(86_431_250)
        ->and($generation->ledgerEntries()->sole()->type)->toBe(LedgerType::Hold)
        ->and($user->fresh()->balance_nano)->toBe(2_000_000_000 - 86_431_250);

    Queue::assertPushedOn('submissions', SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

it('refuses a generation the user cannot afford and saves nothing', function () {
    $user = fundedUser(50_000_000);

    expect(fn () => app(CreateGeneration::class)($user, 'a cat', Size::Square, Quality::High, []))
        ->toThrow(InsufficientCredits::class);

    expect(Generation::count())->toBe(0)
        ->and($user->fresh()->balance_nano)->toBe(50_000_000);
    Queue::assertNothingPushed();
});

it('prices reference images into the estimate', function () {
    $generation = app(CreateGeneration::class)(fundedUser(), 'a cat', Size::Square, Quality::Low, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]);

    // min: 1 text × 5,000 + 1,530 image × 10,000 + 280 output × 40,000 + 0 reasoning
    expect($generation->est_cost_min_nano)->toBe(26_505_000)
        ->and($generation->reference_images)->toHaveCount(2);
});
