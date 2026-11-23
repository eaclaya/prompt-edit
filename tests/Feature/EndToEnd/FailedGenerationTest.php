<?php

use App\Contracts\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGeneration;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\EndToEnd\Studio;

beforeEach(fn () => Studio::boot());
afterEach(fn () => Studio::shutdown());

it('charges nothing and releases the hold when Pixelforge fails the generation', function () {
    $user = Studio::register();
    $generation = Studio::submit();

    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_failed']);
    Studio::submitJobs();
    Studio::deliver(Studio::event($generation, 'generation.processing'));
    Studio::deliver(Studio::event($generation, 'generation.failed'));

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->error_code)->toBe('internal_error')
        ->and($fresh->charged_nanos)->toEqualMoney(0)
        ->and($fresh->provider_cost_nanos)->toEqualMoney(0)
        ->and($user)->toHaveBalance('1.00')
        ->and(app(Ledger::class)->available($user))->toEqualMoney('1.00')
        ->and($user->creditTransactions()->orderBy('id')->pluck('type')->all())->toBe([
            LedgerEntryType::Welcome, LedgerEntryType::Hold, LedgerEntryType::HoldRelease,
        ]);
    Studio::assertBooksBalance($user);

    test()->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page
        ->where('generations.data.0.status', 'failed')
        ->where('generations.data.0.charged', '0.000000000')
        ->where('generations.data.0.error.code', 'internal_error'));
});

it('charges nothing and releases the hold when Pixelforge refuses the prompt', function () {
    $user = Studio::register();
    $generation = Studio::submit(['prompt' => 'Something against the content policy [blocked]']);

    Studio::$pixelforge->failsWith('content_policy_violation');
    Studio::submitJobs();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->error_code)->toBe('content_policy_violation')
        ->and($fresh->charged_nanos)->toEqualMoney(0)
        ->and($user)->toHaveBalance('1.00')
        ->and(app(Ledger::class)->available($user))->toEqualMoney('1.00');
    Studio::assertBooksBalance($user);
});

it('retries an overloaded Pixelforge and charges once it succeeds', function () {
    $user = Studio::register();
    $generation = Studio::submit();

    Studio::$pixelforge->failsWith('overloaded', retryAfter: 2)->acceptsGeneration(['id' => 'gen_e2e_retried']);
    Studio::submitJobs();
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);

    // The sync driver cannot re-run a released job; the worker would, after Retry-After.
    test()->travel(5)->seconds();
    dispatch(new SubmitGeneration($generation));
    Studio::submitJobs();

    Studio::deliver(Studio::event($generation, 'generation.succeeded'));

    Studio::$pixelforge->assertCreatedTimes(2);
    Studio::$pixelforge->assertCreated(fn (array $body, array $headers) => $headers['Idempotency-Key'] === $generation->id);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($user)->toHaveBalance('0.6181125');
    Studio::assertBooksBalance($user);
});
