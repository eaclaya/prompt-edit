<?php

use App\Enums\GenerationStatus;
use App\Pixelforge\Exceptions\ConnectionFailed;
use App\Pixelforge\Exceptions\Overloaded;
use App\Pixelforge\Exceptions\RateLimited;
use App\Pixelforge\PixelforgeClient;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    $this->pixelforge = PixelforgeFake::fake();
    $this->client = app(PixelforgeClient::class);
});

it('accepts a generation, echoing what was requested', function () {
    $this->pixelforge->acceptsGeneration();

    $generation = $this->client->createGeneration([
        'prompt' => 'A cat [slow]',
        'size' => '1024x1536',
        'quality' => 'low',
        'reference_images' => [],
        'metadata' => ['local_id' => '01KAB'],
    ], '01KAB');

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->id)->toStartWith('gen_')
        ->and($generation->prompt)->toBe('A cat [slow]')
        ->and($generation->size->value)->toBe('1024x1536')
        ->and($generation->localId())->toBe('01KAB')
        ->and($generation->idempotentReplayed)->toBeFalse();

    $this->pixelforge->assertCreated(fn (array $body, array $headers) => $body['prompt'] === 'A cat [slow]'
        && $headers['Idempotency-Key'] === '01KAB');
    $this->pixelforge->assertCreatedTimes(1);
});

it('can accept with overrides, or as an idempotent replay', function () {
    $this->pixelforge->acceptsGeneration(['id' => 'gen_fixed', 'status' => 'processing'], replayed: true);

    $generation = $this->client->createGeneration(['prompt' => 'x'], 'k');

    expect($generation->id)->toBe('gen_fixed')
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->idempotentReplayed)->toBeTrue();
});

it('plays queued responses in order and then repeats the last one', function () {
    $this->pixelforge
        ->failsWith('overloaded', retryAfter: 2)
        ->failsWith('rate_limit_exceeded', retryAfter: 30)
        ->acceptsGeneration();

    expect(fn () => $this->client->createGeneration(['prompt' => 'x'], 'k'))->toThrow(fn (Overloaded $e) => expect($e->retryAfter)->toBe(2))
        ->and(fn () => $this->client->createGeneration(['prompt' => 'x'], 'k'))->toThrow(fn (RateLimited $e) => expect($e->retryAfter)->toBe(30))
        ->and($this->client->createGeneration(['prompt' => 'x'], 'k')->status)->toBe(GenerationStatus::Queued)
        ->and($this->client->createGeneration(['prompt' => 'x'], 'k')->status)->toBe(GenerationStatus::Queued);

    $this->pixelforge->assertCreatedTimes(4);
});

it('returns a generation for the requested id from any fixture', function () {
    $this->pixelforge->returnsGeneration('generation.succeeded', ['metadata' => ['local_id' => '01KAB']]);

    $generation = $this->client->getGeneration('gen_abc');

    expect($generation->id)->toBe('gen_abc')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->usage?->total())->toBe(10411)
        ->and($generation->localId())->toBe('01KAB');
});

it('lists generations', function () {
    $this->pixelforge->listsGenerations([
        ['id' => 'gen_1', 'status' => 'processing'],
        ['id' => 'gen_2', 'status' => 'failed'],
    ], nextCursor: 'next');

    $page = $this->client->listGenerations();

    expect(array_map(fn ($g) => $g->id, $page->data))->toBe(['gen_1', 'gen_2'])
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('next');
});

it('returns pricing, with overrides', function () {
    $this->pixelforge->returnsPricing(['rates' => ['output_tokens' => '50.00']]);

    $pricing = $this->client->getPricing();

    expect($pricing->rates->output->toDecimal())->toBe('50.000000000')
        ->and($pricing->rates->inputText->toDecimal())->toBe('5.000000000');
});

it('returns usage for the requested range with zeros for missing days and a computed total', function () {
    $this->pixelforge->returnsUsage([
        '2026-11-01' => ['generations' => 2, 'output_tokens' => 2200, 'cost_usd' => '0.088000000'],
        '2026-11-03' => ['generations' => 1, 'output_tokens' => 280, 'cost_usd' => '0.011200000'],
    ]);

    $usage = $this->client->getUsage('2026-11-01', '2026-11-03');

    expect($usage->startDate)->toBe('2026-11-01')
        ->and(array_map(fn ($d) => $d->date, $usage->days))->toBe(['2026-11-01', '2026-11-02', '2026-11-03'])
        ->and($usage->forDate('2026-11-02')->cost->isZero())->toBeTrue()
        ->and($usage->total->generations)->toBe(3)
        ->and($usage->total->usage->outputTokens)->toBe(2480)
        ->and($usage->total->cost->toDecimal())->toBe('0.099200000');

    $this->pixelforge->assertUsageRequested('2026-11-01', '2026-11-03');
});

it('fails any endpoint with a documented error', function () {
    $this->pixelforge->failsWith('date_range_too_long', endpoint: 'usage');

    $this->client->getUsage('2026-01-01', '2026-12-31');
})->throws(App\Pixelforge\Exceptions\DateRangeTooLong::class);

it('can fail to connect', function () {
    $this->pixelforge->failsToConnect('retrieve');

    $this->client->getGeneration('gen_1');
})->throws(ConnectionFailed::class);

it('refuses requests it has no response for', function () {
    $this->client->getPricing();
})->throws(LogicException::class, 'PixelforgeFake has no response for [pricing]');

it('asserts that nothing was created', function () {
    $this->pixelforge->assertNothingCreated();
});
