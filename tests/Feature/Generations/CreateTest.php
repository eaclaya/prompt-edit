<?php

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeLedger;

beforeEach(function () {
    $this->withoutVite();
    $this->ledger = FakeLedger::swap();
    $this->user = User::factory()->withBalance('1.00')->create();
});

it('renders the create page with sizes, qualities, defaults and limits', function () {
    $this->actingAs($this->user)
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('sizes', [
                ['value' => '1024x1024', 'label' => 'Square (1024 × 1024)'],
                ['value' => '1536x1024', 'label' => 'Landscape (1536 × 1024)'],
                ['value' => '1024x1536', 'label' => 'Portrait (1024 × 1536)'],
            ])
            ->where('qualities', [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
            ])
            ->where('defaults', ['size' => '1024x1024', 'quality' => 'medium'])
            ->where('limits', ['prompt_max_length' => 4000, 'reference_images_max' => 4]));
});

it('sends the available balance from the ledger as a decimal string', function () {
    $this->ledger->setBalance($this->user, Money::of('2.5'));

    $this->actingAs($this->user)
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', '2.500000000'));
});

it('sends what is available after open holds', function () {
    $generation = Generation::factory()->for($this->user)->create();
    $this->ledger->hold($generation, Money::of('0.40'));

    $this->actingAs($this->user)
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', '0.600000000'));
});
