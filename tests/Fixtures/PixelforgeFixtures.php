<?php

namespace Tests\Fixtures;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads the Pixelforge docs payloads in tests/Fixtures/pixelforge.
 */
final class PixelforgeFixtures
{
    /**
     * @param  string  $name  e.g. "generation.succeeded", "pricing", "errors/overloaded"
     * @param  array<string, mixed>  $overrides  merged recursively; lists are replaced
     * @return array<string, mixed>
     */
    public static function load(string $name, array $overrides = []): array
    {
        $path = self::directory()."/{$name}.json";

        if (! is_file($path)) {
            throw new InvalidArgumentException("No Pixelforge fixture named [{$name}].");
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return self::merge($payload, $overrides);
    }

    /**
     * A webhook event envelope with a fresh evt_ id around the matching generation fixture.
     *
     * @param  string  $type  generation.processing | generation.succeeded | generation.failed | ping
     * @param  array<string, mixed>  $generation  overrides for data.object
     * @return array<string, mixed>
     */
    public static function event(string $type, array $generation = [], ?string $eventId = null): array
    {
        $event = self::load("event.{$type}");
        $event['id'] = $eventId ?? 'evt_'.Str::upper((string) Str::ulid());

        if ($event['data']['object'] !== null) {
            $event['data']['object'] = self::merge($event['data']['object'], $generation);
        }

        return $event;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];

        foreach (['', 'errors/'] as $prefix) {
            foreach (glob(self::directory()."/{$prefix}*.json") ?: [] as $file) {
                $names[] = $prefix.basename($file, '.json');
            }
        }

        return $names;
    }

    private static function directory(): string
    {
        return __DIR__.'/pixelforge';
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
