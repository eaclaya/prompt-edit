<?php

use App\Models\User;

// The live site publishes the regular user's credentials for reviewers, so the login form can carry them.

beforeEach(function () {
    config([
        'seed.demo' => ['name' => 'Demo User', 'email' => 'demo@example.test', 'password' => 'demo-secret-1'],
    ]);
});

it('pre-fills the regular user\'s credentials when switched on', function () {
    config(['seed.prefill_login' => true]);

    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('prefill.email', 'demo@example.test')
            ->where('prefill.password', 'demo-secret-1'));
});

it('leaves the login form empty by default', function () {
    config(['seed.prefill_login' => false]);

    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('prefill', null));
});

it('never pre-fills the admin account', function () {
    config([
        'seed.prefill_login' => true,
        'seed.admin' => ['name' => 'Operator', 'email' => 'admin@example.test', 'password' => 'admin-secret-1'],
    ]);

    $html = $this->withoutVite()->get(route('login'))->getContent();

    expect($html)->not->toContain('admin@example.test')
        ->and($html)->not->toContain('admin-secret-1');
});

it('does not pre-fill without a demo password', function () {
    config(['seed.prefill_login' => true, 'seed.demo.password' => null]);

    $this->withoutVite()->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('prefill', null));
});

it('signs the regular user in with the pre-filled credentials', function () {
    User::factory()->create(['email' => 'demo@example.test', 'password' => 'demo-secret-1']);

    $this->post(route('login.store'), ['email' => 'demo@example.test', 'password' => 'demo-secret-1'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
