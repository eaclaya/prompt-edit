<?php

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\SignedWebhook;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against Postgres (the `testing` database) inside a
| transaction. Unit tests boot the app too, but should not touch the database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/** Normalise Money, an int of nanodollars or a decimal string to Money. */
function asMoney(mixed $value): Money
{
    return match (true) {
        $value instanceof Money => $value,
        is_int($value) => Money::ofNanos($value),
        is_string($value) => Money::of($value),
        default => throw new InvalidArgumentException('Expected Money, an int of nanodollars or a decimal string, got '.get_debug_type($value).'.'),
    };
}

// expect($generation->charged_nanos)->toEqualMoney('0.373875')
expect()->extend('toEqualMoney', function (Money|int|string $expected) {
    $actual = asMoney($this->value);
    $expected = asMoney($expected);

    expect($actual->nanos)->toBe(
        $expected->nanos,
        "Expected {$actual->toDecimal()} to equal {$expected->toDecimal()}.",
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A Pixelforge webhook body plus signed headers (secret: config('pixelforge.webhook_secret')).
 *
 * @param  array<string, mixed>  $payload  e.g. PixelforgeFixtures::event('generation.succeeded')
 */
function signedWebhook(array $payload, ?int $timestamp = null, ?string $secret = null, int $attempt = 1): SignedWebhook
{
    return SignedWebhook::make($payload, $timestamp, $secret, $attempt);
}

/**
 * POST a signed delivery to /webhooks/pixelforge with the raw body, as Pixelforge would.
 *
 * @param  array<string, mixed>|SignedWebhook  $payload
 */
function postWebhook(array|SignedWebhook $payload, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $webhook = $payload instanceof SignedWebhook ? $payload : signedWebhook($payload, $timestamp, $secret);

    return test()->call('POST', '/webhooks/pixelforge', server: $webhook->server(), content: $webhook->body);
}
