<?php

namespace App\Pixelforge;

use Illuminate\Container\Attributes\Config;

/**
 * Verifies `Pixelforge-Signature: t=<unix>,v1=<hex>` headers: an HMAC-SHA256
 * of "<t>.<raw body>" with the endpoint's signing secret. Old timestamps are
 * rejected so captured requests can't be replayed.
 */
final readonly class SignatureVerifier
{
    public function __construct(
        #[Config('pixelforge.webhook_secret')]
        private ?string $secret,
        #[Config('pixelforge.webhook_tolerance')]
        private int $toleranceSeconds,
    ) {}

    public function isValid(?string $header, string $rawBody, ?int $now = null): bool
    {
        if ($this->secret === null || $this->secret === '' || $header === null || $header === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = self::sign($timestamp, $rawBody, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(int $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
