<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\EndToEnd\Studio;

beforeEach(fn () => Studio::boot());
afterEach(fn () => Studio::shutdown());

/** GET /v1/generations/{id} requests sent so far. */
function polls(): int
{
    return Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/generations/'))->count();
}

/** @return array<string, mixed> overrides for a generation fixture about $generation, finished now */
function finishedNow(App\Models\Generation $generation): array
{
    $now = CarbonImmutable::now('UTC');

    return [
        'metadata' => ['local_id' => $generation->id],
        'completed_at' => $now->toIso8601ZuluString(),
    ];
}

it('recovers a generation whose webhooks never arrived', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_lost']);
    Studio::submitJobs();

    // Still inside the quiet period: nothing is polled.
    test()->travel(30)->seconds();
    test()->artisan('generations:reconcile')->assertSuccessful();
    expect(polls())->toBe(0);

    test()->travel(2)->minutes();
    Studio::$pixelforge->returnsGeneration('generation.succeeded', [
        ...finishedNow($generation),
        'image_url_expires_at' => CarbonImmutable::now('UTC')->addDay()->toIso8601ZuluString(),
    ]);
    test()->artisan('generations:reconcile')->assertSuccessful();

    $fresh = $generation->fresh();
    expect(polls())->toBe(1)
        ->and($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875')
        ->and($user)->toHaveBalance('0.6181125');

    // Re-running, and the webhook finally turning up, change nothing.
    test()->travel(2)->minutes();
    test()->artisan('generations:reconcile')->assertSuccessful();
    Studio::deliver(Studio::event($generation, 'generation.succeeded'));

    expect(polls())->toBe(1)
        ->and($user->creditTransactions()->where('type', LedgerEntryType::Charge)->count())->toBe(1)
        ->and($user)->toHaveBalance('0.6181125');
    Studio::assertBooksBalance($user);
});

it('releases the hold of a generation that failed while its webhooks were lost', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_lost_fail']);
    Studio::submitJobs();
    Studio::deliver(Studio::event($generation, 'generation.processing'));

    test()->travel(2)->minutes();
    Studio::$pixelforge->returnsGeneration('generation.failed', finishedNow($generation));
    test()->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($user)->toHaveBalance('1.00');
    Studio::assertBooksBalance($user);
});
