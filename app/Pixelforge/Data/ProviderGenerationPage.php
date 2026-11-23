<?php

namespace App\Pixelforge\Data;

/** One page of GET /v1/generations, newest first. */
final readonly class ProviderGenerationPage
{
    /** @param  list<ProviderGeneration>  $data */
    public function __construct(
        public array $data,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /** @param  array<string, mixed>  $page */
    public static function fromArray(array $page): self
    {
        /** @var list<array<string, mixed>> $data */
        $data = $page['data'] ?? [];

        return new self(
            data: array_map(fn (array $object) => ProviderGeneration::fromArray($object), $data),
            hasMore: (bool) ($page['has_more'] ?? false),
            nextCursor: isset($page['next_cursor']) ? (string) $page['next_cursor'] : null,
        );
    }
}
