<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The root URL is the studio itself: History, which sends guests to the login page. */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_opens_history()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('generations.index'));
    }

    public function test_guests_at_the_root_url_end_up_on_the_login_page()
    {
        $this->followingRedirects()
            ->withoutVite()
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_there_is_no_welcome_page()
    {
        $this->assertFileDoesNotExist(resource_path('js/pages/Welcome.vue'));
    }
}
