<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A Pixelforge generation object, from an API response or a webhook.
 */
final readonly class GenerationPayload
{
    /**
     * @param  array<string, string>|null  $rates  USD per 1M tokens, fixed when the generation was created
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata = [],
        public ?array $rates = null,
        public ?TokenUsage $usage = null,
        public ?string $imageUrl = null,
        public ?CarbonImmutable $imageUrlExpiresAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $completedAt = null,
        public bool $replayed = false,
    ) {
        if ($status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('Pixelforge never reports a pending generation.');
        }
    }

    /** @param  array<string, mixed>  $object */
    public static function fromArray(array $object, bool $replayed = false): self
    {
        if (! isset($object['id'], $object['status'])) {
            throw new InvalidArgumentException('Generation object is missing its id or status.');
        }

        $time = fn (string $key): ?CarbonImmutable => empty($object[$key]) ? null : CarbonImmutable::parse($object[$key])->utc();

        return new self(
            id: (string) $object['id'],
            status: GenerationStatus::from($object['status']),
            metadata: array_map('strval', (array) ($object['metadata'] ?? [])),
            rates: isset($object['rates']) ? array_map('strval', (array) $object['rates']) : null,
            usage: isset($object['usage']) ? TokenUsage::fromArray((array) $object['usage']) : null,
            imageUrl: $object['image_url'] ?? null,
            imageUrlExpiresAt: $time('image_url_expires_at'),
            errorCode: $object['error']['code'] ?? null,
            errorMessage: $object['error']['message'] ?? null,
            createdAt: $time('created_at'),
            startedAt: $time('started_at'),
            completedAt: $time('completed_at'),
            replayed: $replayed,
        );
    }

    /** Our generation ULID, which we send as metadata.local_id. */
    public function localId(): ?string
    {
        return $this->metadata['local_id'] ?? null;
    }
}
