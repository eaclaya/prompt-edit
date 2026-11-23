<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a succeeded generation's image to the public disk, because Pixelforge's image_url
 * expires 24 h after completion. If the download fails, the generation keeps Pixelforge's link.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    private const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(public readonly string $generationId) {}

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        try {
            $response = Http::timeout(30)->connectTimeout(5)->get($generation->image_url);
        } catch (ConnectionException $e) {
            $this->retryOrGiveUp($generation, $e->getMessage());

            return;
        }

        if (! $response->successful()) {
            $this->retryOrGiveUp($generation, "HTTP {$response->status()}");

            return;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $path = "generations/{$generation->id}.".(self::EXTENSIONS[$type] ?? 'png');

        Storage::disk('public')->put($path, $response->body());
        $generation->forceFill(['image_path' => $path])->save();
    }

    private function retryOrGiveUp(Generation $generation, string $reason): void
    {
        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);

            return;
        }

        Log::warning('Could not copy a generation image; keeping Pixelforge\'s link.', ['generation' => $generation->id, 'reason' => $reason]);
    }
}
