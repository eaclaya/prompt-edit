<?php

namespace App\Pixelforge\Data;

use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use Carbon\CarbonImmutable;

/**
 * A Pixelforge generation object, from a response or a webhook's data.object.
 * The status is one of queued / processing / succeeded / failed (never pending).
 */
final readonly class ProviderGeneration
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $raw  the object exactly as received
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?Usage $usage,
        public Rates $rates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $raw,
        public bool $idempotentReplayed = false,
    ) {}

    /** @param  array<string, mixed>  $object */
    public static function fromArray(array $object, bool $idempotentReplayed = false): self
    {
        /** @var array{code?: string, message?: string}|null $error */
        $error = $object['error'] ?? null;
        /** @var array<string, mixed>|null $usage */
        $usage = $object['usage'] ?? null;
        /** @var array<string, mixed> $rates */
        $rates = $object['rates'] ?? [];
        /** @var list<string> $referenceImages */
        $referenceImages = $object['reference_images'] ?? [];
        /** @var array<string, string> $metadata */
        $metadata = $object['metadata'] ?? [];

        return new self(
            id: (string) $object['id'],
            status: GenerationStatus::from((string) $object['status']),
            prompt: (string) ($object['prompt'] ?? ''),
            size: Size::from((string) ($object['size'] ?? Size::default()->value)),
            quality: Quality::from((string) ($object['quality'] ?? Quality::default()->value)),
            referenceImages: $referenceImages,
            metadata: $metadata,
            imageUrl: isset($object['image_url']) ? (string) $object['image_url'] : null,
            imageUrlExpiresAt: self::time($object['image_url_expires_at'] ?? null),
            usage: $usage === null ? null : Usage::fromArray($usage),
            rates: Rates::fromArray($rates),
            errorCode: $error['code'] ?? null,
            errorMessage: $error['message'] ?? null,
            createdAt: self::time($object['created_at'] ?? null) ?? CarbonImmutable::now('UTC'),
            startedAt: self::time($object['started_at'] ?? null),
            completedAt: self::time($object['completed_at'] ?? null),
            raw: $object,
            idempotentReplayed: $idempotentReplayed,
        );
    }

    /** metadata.local_id: our generation's ULID, sent on create. */
    public function localId(): ?string
    {
        return $this->metadata['local_id'] ?? null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
    }
}
