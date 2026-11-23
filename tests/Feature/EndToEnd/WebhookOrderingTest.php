<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\WebhookEvent;
use Tests\Feature\EndToEnd\Studio;

beforeEach(fn () => Studio::boot());
afterEach(fn () => Studio::shutdown());

it('processes a redelivered event once and charges once', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_dup']);
    Studio::submitJobs();

    $succeeded = Studio::event($generation, 'generation.succeeded');
    Studio::deliver($succeeded, attempt: 1)->assertJsonMissing(['duplicate' => true]);
    Studio::deliver($succeeded, attempt: 2)->assertJson(['duplicate' => true]);

    expect(WebhookEvent::where('event_id', $succeeded['id'])->count())->toBe(1)
        ->and($user->creditTransactions()->where('type', LedgerEntryType::Charge)->count())->toBe(1)
        ->and($user)->toHaveBalance('0.6181125');
    Studio::assertBooksBalance($user);
});

it('charges once when the same success arrives under two event ids', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_twice']);
    Studio::submitJobs();

    Studio::deliver(Studio::event($generation, 'generation.succeeded'));
    Studio::deliver(Studio::event($generation, 'generation.succeeded'));

    expect(WebhookEvent::count())->toBe(2)
        ->and($user->creditTransactions()->where('type', LedgerEntryType::Charge)->count())->toBe(1)
        ->and($user)->toHaveBalance('0.6181125');
});

it('matches a success that lands before the POST response by metadata.local_id', function () {
    $user = Studio::register();
    $generation = Studio::submit();

    // Pixelforge finishes and the webhook is processed while our POST is still waiting for its 202.
    Studio::duringSubmission(fn () => Studio::deliver(Studio::event($generation, 'generation.succeeded', providerId: 'gen_e2e_early')));
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_early']);
    Studio::submitJobs();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->provider_id)->toBe('gen_e2e_early')
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875')
        ->and($user->creditTransactions()->where('type', LedgerEntryType::Charge)->count())->toBe(1)
        ->and($user)->toHaveBalance('0.6181125');
    Studio::$pixelforge->assertCreatedTimes(1);
    Studio::assertBooksBalance($user);
});

it('ignores a processing event that arrives after the success', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_late']);
    Studio::submitJobs();

    $processing = Studio::event($generation, 'generation.processing');
    Studio::deliver(Studio::event($generation, 'generation.succeeded'));
    Studio::deliver($processing);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($user)->toHaveBalance('0.6181125');
    Studio::assertBooksBalance($user);
});

it('ignores a processing event that arrives after a failure', function () {
    $user = Studio::register();
    $generation = Studio::submit();
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_e2e_late_fail']);
    Studio::submitJobs();

    $processing = Studio::event($generation, 'generation.processing');
    Studio::deliver(Studio::event($generation, 'generation.failed'));
    Studio::deliver($processing);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($user)->toHaveBalance('1.00');
    Studio::assertBooksBalance($user);
});
