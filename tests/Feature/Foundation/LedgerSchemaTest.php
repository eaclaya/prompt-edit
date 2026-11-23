<?php

use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('stores signed ledger rows with the balance after them', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    $row = CreditTransaction::factory()->for($user)->for($generation)->create([
        'type' => LedgerEntryType::Charge,
        'amount_nanos' => Money::of('-0.25'),
        'balance_after_nanos' => Money::of('0.75'),
        'meta' => ['note' => 'test'],
    ])->fresh();

    expect($row->type)->toBe(LedgerEntryType::Charge)
        ->and($row->amount_nanos->toDecimal())->toBe('-0.250000000')
        ->and($row->balance_after_nanos->toDecimal())->toBe('0.750000000')
        ->and($row->meta)->toBe(['note' => 'test'])
        ->and($row->user->is($user))->toBeTrue()
        ->and($row->generation->is($generation))->toBeTrue()
        ->and($user->creditTransactions()->count())->toBe(1)
        ->and($generation->creditTransactions()->count())->toBe(1);
});

it('allows one row per generation and type', function () {
    $generation = Generation::factory()->create();

    CreditTransaction::factory()->for($generation)->create(['type' => LedgerEntryType::Hold, 'user_id' => $generation->user_id]);
    CreditTransaction::factory()->for($generation)->create(['type' => LedgerEntryType::Charge, 'user_id' => $generation->user_id]);
    CreditTransaction::factory()->for($generation)->create(['type' => LedgerEntryType::Charge, 'user_id' => $generation->user_id]);
})->throws(UniqueConstraintViolationException::class);

it('allows many rows of the same type without a generation', function () {
    $user = User::factory()->create();

    CreditTransaction::factory()->count(2)->for($user)->create(['type' => LedgerEntryType::Purchase, 'generation_id' => null]);

    expect($user->creditTransactions()->count())->toBe(2);
});

it('makes a purchase token usable once', function () {
    $user = User::factory()->create();

    CreditTransaction::factory()->for($user)->create(['type' => LedgerEntryType::Purchase, 'idempotency_key' => 'tok_1']);
    CreditTransaction::factory()->for($user)->create(['type' => LedgerEntryType::Purchase, 'idempotency_key' => 'tok_1']);
})->throws(UniqueConstraintViolationException::class);

it('records each webhook event id once', function () {
    $row = fn () => [
        'event_id' => 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A',
        'type' => 'generation.succeeded',
        'generation_provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'payload' => json_encode(['id' => 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A']),
        'received_at' => now(),
    ];

    expect(DB::table('webhook_events')->insertOrIgnore($row()))->toBe(1)
        ->and(DB::table('webhook_events')->insertOrIgnore($row()))->toBe(0);

    $event = WebhookEvent::sole();

    expect($event->payload)->toBe(['id' => 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'])
        ->and($event->received_at)->not->toBeNull()
        ->and($event->processed_at)->toBeNull()
        ->and(WebhookEvent::factory()->create()->event_id)->toStartWith('evt_');
});
