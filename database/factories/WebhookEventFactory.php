<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::upper((string) Str::ulid());
        $generationId = 'gen_'.Str::upper((string) Str::ulid());

        return [
            'event_id' => $eventId,
            'type' => 'generation.processing',
            'generation_provider_id' => $generationId,
            'payload' => [
                'id' => $eventId,
                'object' => 'event',
                'type' => 'generation.processing',
                'data' => ['object' => ['id' => $generationId, 'object' => 'generation', 'status' => 'processing']],
            ],
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}
