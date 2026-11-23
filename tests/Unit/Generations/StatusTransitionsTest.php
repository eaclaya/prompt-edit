<?php

use App\Enums\GenerationStatus as S;
use App\Generations\StatusTransitions;

// Every (from, to) pair. Forward only; succeeded and failed are terminal; staying put is not a transition.
dataset('transitions', function () {
    $allowed = [
        'pending' => ['queued', 'processing', 'succeeded', 'failed'],
        'queued' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => [],
    ];

    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
        }
    }
});

it('allows only forward moves out of a non-final status', function (S $from, S $to, bool $allowed) {
    expect(StatusTransitions::allows($from, $to))->toBe($allowed);
})->with('transitions');

