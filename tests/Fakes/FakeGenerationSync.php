<?php

namespace Tests\Fakes;

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Data\ProviderGeneration;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Records every apply() and returns the matching local generation (by metadata.local_id,
 * then provider_id). By default it changes nothing; writeThrough() makes it copy the
 * provider fields onto the row when the status moves forward (no ledger calls).
 */
final class FakeGenerationSync implements GenerationSync
{
    /** @var list<ProviderGeneration> */
    public array $applied = [];

    private bool $writeThrough = false;

    public static function swap(): self
    {
        return app()->instance(GenerationSync::class, new self);
    }

    public function writeThrough(bool $enabled = true): self
    {
        $this->writeThrough = $enabled;

        return $this;
    }

    public function apply(ProviderGeneration $generation): ?Generation
    {
        $this->applied[] = $generation;

        $local = ($generation->localId() !== null ? Generation::find($generation->localId()) : null)
            ?? Generation::where('provider_id', $generation->id)->first();

        if ($local !== null && $this->writeThrough && self::rank($generation->status) > self::rank($local->status)) {
            $local->forceFill([
                'provider_id' => $generation->id,
                'status' => $generation->status,
                'rates' => $generation->raw['rates'] ?? $generation->rates->toArray(),
                'usage' => $generation->usage?->toArray(),
                'image_url' => $generation->imageUrl,
                'image_url_expires_at' => $generation->imageUrlExpiresAt,
                'error_code' => $generation->errorCode,
                'error_message' => $generation->errorMessage,
                'started_at' => $generation->startedAt,
                'completed_at' => $generation->completedAt,
            ])->save();
        }

        return $local;
    }

    /** @param  Closure(ProviderGeneration): bool|null  $callback */
    public function assertApplied(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            array_filter($this->applied, fn (ProviderGeneration $g) => $callback === null || $callback($g)),
            'No matching provider generation was applied.',
        );
    }

    public function assertAppliedTimes(int $times): void
    {
        Assert::assertCount($times, $this->applied);
    }

    public function assertNothingApplied(): void
    {
        $this->assertAppliedTimes(0);
    }

    private static function rank(GenerationStatus $status): int
    {
        return match ($status) {
            GenerationStatus::Pending => 0,
            GenerationStatus::Queued => 1,
            GenerationStatus::Processing => 2,
            GenerationStatus::Succeeded, GenerationStatus::Failed => 3,
        };
    }
}
