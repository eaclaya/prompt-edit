<?php

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeCostCalculator;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\FakeMarkup;
use Tests\Fixtures\PixelforgeFixtures;

// Pixelforge's image links expire 24 h after completion, so we keep our own copy.

const FAKE_PNG = "\x89PNG\r\n\x1a\nfake-image-bytes";

beforeEach(function () {
    config(['pixelforge.store_images' => true]);
    Storage::fake('public');
    FakeLedger::swap();
    FakeCostCalculator::swap();
    FakeMarkup::swap();
});

function succeedAtPixelforge(Generation $generation): void
{
    app(GenerationSync::class)->apply(ProviderGeneration::fromArray(
        PixelforgeFixtures::load('generation.succeeded', ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]),
    ));
}

it('keeps a copy of the image when a generation succeeds', function () {
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response(FAKE_PNG, 200, ['Content-Type' => 'image/png'])]);
    $generation = Generation::factory()->processing()->create();

    succeedAtPixelforge($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->image_path)->toBe("generations/{$generation->id}.png");
    Storage::disk('public')->assertExists($generation->image_path);
    expect(Storage::disk('public')->get($generation->image_path))->toBe(FAKE_PNG);
});

it('still succeeds, with Pixelforge\'s link, when the image cannot be downloaded', function () {
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('gone', 404)]);
    $generation = Generation::factory()->processing()->create();

    succeedAtPixelforge($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->image_path)->toBeNull()
        ->and($generation->image_url)->not->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('downloads each image once', function () {
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response(FAKE_PNG, 200, ['Content-Type' => 'image/png'])]);
    $generation = Generation::factory()->succeeded()->create();

    StoreGenerationImage::dispatchSync($generation->id);
    StoreGenerationImage::dispatchSync($generation->id);

    Http::assertSentCount(1);
    expect($generation->fresh()->image_path)->not->toBeNull();
});

it('does nothing when copying is switched off', function () {
    config(['pixelforge.store_images' => false]);
    Http::fake();
    $generation = Generation::factory()->processing()->create();

    succeedAtPixelforge($generation);

    Http::assertNothingSent();
    expect($generation->fresh()->image_path)->toBeNull();
});

it('serves our copy on the pages, and never as expired', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->succeeded()->imageExpired()->create([
        'image_path' => 'generations/copy.png',
    ]);
    $url = Storage::disk('public')->url('generations/copy.png');

    $this->withoutVite()->actingAs($user)->get(route('generations.show', $generation))
        ->assertInertia(fn ($page) => $page
            ->where('generation.image_url', $url)
            ->where('generation.image_expired', false)
            ->where('generation.image_url_expires_at', null));

    $this->get(route('generations.index'))
        ->assertInertia(fn ($page) => $page
            ->where('generations.data.0.image_url', $url)
            ->where('generations.data.0.image_expired', false));
});
