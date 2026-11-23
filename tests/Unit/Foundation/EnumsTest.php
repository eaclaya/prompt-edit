<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Quality;
use App\Enums\Size;
use App\Enums\TokenType;

it('has the local generation statuses, of which only succeeded and failed are final', function () {
    expect(array_column(GenerationStatus::cases(), 'value'))
        ->toBe(['pending', 'queued', 'processing', 'succeeded', 'failed'])
        ->and(array_map(fn (GenerationStatus $s) => $s->isFinal(), GenerationStatus::cases()))
        ->toBe([false, false, false, true, true]);
});

it('shows pending as queued in the UI', function () {
    expect(GenerationStatus::Pending->label())->toBe('Queued')
        ->and(GenerationStatus::Queued->label())->toBe('Queued')
        ->and(GenerationStatus::Processing->label())->toBe('Processing')
        ->and(GenerationStatus::Succeeded->label())->toBe('Succeeded')
        ->and(GenerationStatus::Failed->label())->toBe('Failed');
});

it('has the sizes and qualities Pixelforge accepts, with its defaults', function () {
    expect(array_column(Size::cases(), 'value'))->toBe(['1024x1024', '1536x1024', '1024x1536'])
        ->and(array_column(Quality::cases(), 'value'))->toBe(['low', 'medium', 'high'])
        ->and(Size::default())->toBe(Size::Square)
        ->and(Quality::default())->toBe(Quality::Medium);
});

it('has the ledger entry types', function () {
    expect(array_column(LedgerEntryType::cases(), 'value'))
        ->toBe(['purchase', 'welcome', 'hold', 'hold_release', 'charge']);
});

it('has the billable token types, keyed as in the API', function () {
    expect(array_column(TokenType::cases(), 'value'))
        ->toBe(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens']);
});
