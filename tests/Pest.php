<?php

use App\Billing\Ledger;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\PriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** Pixelforge's published price list, as returned by GET /v1/pricing. */
function pixelforgePricingResponse(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true, flags: JSON_THROW_ON_ERROR);
}

function pixelforgePrices(): PriceList
{
    return PriceList::fromArray(pixelforgePricingResponse());
}

/**
 * A Pixelforge generation object as the API and webhooks return it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_01jcz8xk4m2n5p7q9r3s6t8v0w',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'a lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pixelforgePricingResponse()['rates'],
        'error' => null,
        'created_at' => '2026-11-18T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

function fundedUser(int $balanceNano = 2_000_000_000): User
{
    $user = User::factory()->create();
    app(Ledger::class)->grant($user, $balanceNano, "test:{$user->id}", 'Test credit');

    return $user->fresh();
}

/**
 * A generation with its maximum price held, as CreateGeneration leaves it.
 *
 * @param  array<string, mixed>  $attributes
 */
function heldGeneration(array $attributes = [], ?User $user = null): Generation
{
    $generation = Generation::factory()->for($user ?? fundedUser())->create($attributes);
    app(Ledger::class)->hold($generation);

    return $generation->fresh();
}

/**
 * Actual usage for the factory's default (medium, square, 100 characters):
 * 25 text, 1100 output, 1234 reasoning tokens.
 * Cost $0.059550000, price $0.074437500.
 */
const SAMPLE_USAGE = ['input_text_tokens' => 25, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1234, 'total_tokens' => 2359];
const SAMPLE_COST_NANO = 59_550_000;
const SAMPLE_PRICE_NANO = 74_437_500;

/**
 * The provider's view of one of our generations.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerGeneration(Generation $generation, string $status, array $overrides = []): array
{
    $base = [
        'id' => $generation->provider_id ?? 'gen_'.strtolower($generation->id),
        'status' => $status,
        'metadata' => ['local_id' => $generation->id, 'user_id' => (string) $generation->user_id],
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-18T14:03:14Z' : null,
    ];

    if ($status === 'succeeded') {
        $base += [
            'usage' => SAMPLE_USAGE,
            'image_url' => 'https://pixelforge.promptedit.dev/images/x.png?expires=1&signature=s',
            'image_url_expires_at' => '2026-11-19T14:03:40Z',
            'completed_at' => '2026-11-18T14:03:40Z',
        ];
    }

    if ($status === 'failed') {
        $base += [
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'],
            'completed_at' => '2026-11-18T14:03:40Z',
        ];
    }

    return pixelforgeGeneration(array_replace($base, $overrides));
}

/**
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function webhookEvent(string $type, array $object, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.Str::lower(Str::random(20)),
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-18T14:03:40Z',
        'data' => ['object' => $object],
    ];
}
