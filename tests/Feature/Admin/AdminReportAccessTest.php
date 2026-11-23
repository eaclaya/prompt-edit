<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    $this->withoutVite();
    PixelforgeFake::fake()->returnsUsage();
});

it('redirects guests to the login page', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});

it('forbids users who are not admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('shows the report to admins', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Report'));
});
