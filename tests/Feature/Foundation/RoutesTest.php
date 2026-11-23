<?php

use App\Http\Middleware\ShareBalance;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\PixelforgeFixtures;

dataset('app routes', [
    'generations.index' => ['GET', fn () => route('generations.index')],
    'generations.create' => ['GET', fn () => route('generations.create')],
    'generations.show' => ['GET', fn () => route('generations.show', Generation::factory()->create())],
    'generations.store' => ['POST', fn () => route('generations.store')],
    'estimate' => ['POST', fn () => route('estimate')],
    'credits.index' => ['GET', fn () => route('credits.index')],
    'credits.purchase' => ['POST', fn () => route('credits.purchase')],
    'admin.report' => ['GET', fn () => route('admin.report')],
]);

it('sends guests to the login page', function (string $method, string $url) {
    $this->call($method, $url)->assertRedirect(route('login'));
})->with('app routes');

it('requires a verified email', function (string $method, string $url) {
    $this->actingAs(User::factory()->unverified()->create())
        ->call($method, $url)
        ->assertRedirect(route('verification.notice'));
})->with('app routes');

it('has the documented paths', function () {
    expect(route('generations.index', absolute: false))->toBe('/generations')
        ->and(route('generations.create', absolute: false))->toBe('/generations/create')
        ->and(route('generations.show', '01KAB', absolute: false))->toBe('/generations/01KAB')
        ->and(route('generations.store', absolute: false))->toBe('/generations')
        ->and(route('estimate', absolute: false))->toBe('/estimate')
        ->and(route('credits.index', absolute: false))->toBe('/credits')
        ->and(route('credits.purchase', absolute: false))->toBe('/credits/purchase')
        ->and(route('admin.report', absolute: false))->toBe('/admin/report')
        ->and(route('webhooks.pixelforge', absolute: false))->toBe('/webhooks/pixelforge');
});

it('returns 404 for a generation that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->get('/generations/01KAB000000000000000000000')
        ->assertNotFound();
});

it('accepts webhooks from Pixelforge without a session, login or CSRF token', function () {
    $route = Route::getRoutes()->getByName('webhooks.pixelforge');

    expect($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->not->toContain('web')
        ->and($route->gatherMiddleware())->not->toContain('auth');

    postWebhook(PixelforgeFixtures::event('ping'))->assertOk();
});

it('reserves a ShareBalance slot in the web middleware group', function () {
    expect(app(Kernel::class)->getMiddlewareGroups()['web'])->toContain(ShareBalance::class);

    $this->get('/')->assertOk();
});
