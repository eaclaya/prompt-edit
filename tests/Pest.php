<?php

use App\Pricing\PriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
