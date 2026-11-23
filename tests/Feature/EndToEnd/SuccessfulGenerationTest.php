<?php

use App\Contracts\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\EndToEnd\Studio;

beforeEach(fn () => Studio::boot());
afterEach(fn () => Studio::shutdown());

it('charges the actual cost once a submitted generation succeeds, and settles the hold', function () {
    $user = Studio::register();
    expect($user)->toHaveBalance('1.00');

    // The docs' example request: high, 1536x1024, one reference image.
    $generation = Studio::submit();

    $max = $generation->est_max_nanos;
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and(app(Ledger::class)->available($user))->toEqualMoney(Money::of('1.00')->subtract($max));

    // The worker sends it: Idempotency-Key and metadata.local_id are the ULID.
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_success']);
    Studio::submitJobs();

    Studio::$pixelforge->assertCreated(fn (array $body, array $headers) => $headers['Idempotency-Key'] === $generation->id
        && $body['metadata'] === ['local_id' => $generation->id]);
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_e2e_success');

    Studio::deliver(Studio::event($generation, 'generation.processing'));
    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);

    test()->travel(16)->seconds();
    Studio::deliver(Studio::event($generation, 'generation.succeeded'));

    // usage 12 + 765 + 6450 + 3184 at the docs' rates = 0.305510, × 1.25 = 0.3818875.
    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->provider_cost_nanos)->toEqualMoney('0.305510')
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875')
        ->and($fresh->charged_nanos->isLessThanOrEqualTo($max))->toBeTrue()
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($user)->toHaveBalance('0.6181125')
        ->and(app(Ledger::class)->available($user))->toEqualMoney('0.6181125');

    expect($user->creditTransactions()->orderBy('id')->pluck('type')->all())->toBe([
        LedgerEntryType::Welcome, LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge,
    ]);
    Studio::assertBooksBalance($user);

    // What the user sees.
    test()->get(route('generations.show', $generation))->assertInertia(fn (Assert $page) => $page
        ->component('generations/Show')
        ->where('generation.status', 'succeeded')
        ->where('generation.charged', '0.381887500')
        ->where('generation.breakdown.provider_cost', '0.305510000'));
    test()->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balance', '0.618112500')
        ->where('available', '0.618112500'));
});

