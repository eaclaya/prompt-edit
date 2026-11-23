<?php

namespace Tests\Fakes;

/**
 * A Pixelforge webhook delivery: the raw JSON body and the headers Pixelforge sends,
 * signed as t=<unix>,v1=hex(HMAC-SHA256("{t}.{body}", whsec)).
 */
final readonly class SignedWebhook
{
    /** @param  array<string, string>  $headers */
    public function __construct(
        public string $body,
        public array $headers,
        public int $timestamp,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function make(array $payload, ?int $timestamp = null, ?string $secret = null, int $attempt = 1): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $secret ??= (string) config('pixelforge.webhook_secret');
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return new self($body, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Pixelforge-Webhooks/1.0',
            'Pixelforge-Event-Id' => (string) ($payload['id'] ?? ''),
            'Pixelforge-Event-Type' => (string) ($payload['type'] ?? ''),
            'Pixelforge-Delivery-Attempt' => (string) $attempt,
            'Pixelforge-Signature' => "t={$timestamp},v1={$signature}",
        ], $timestamp);
    }

    /** Copy with the signature header replaced (or removed with null). */
    public function withSignature(?string $signature): self
    {
        $headers = $this->headers;
        unset($headers['Pixelforge-Signature']);

        if ($signature !== null) {
            $headers['Pixelforge-Signature'] = $signature;
        }

        return new self($this->body, $headers, $this->timestamp);
    }

    /** The headers as $_SERVER keys, for $this->call(..., server: $webhook->server(), content: $webhook->body). */
    public function server(): array
    {
        $server = [];

        foreach ($this->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : "HTTP_{$key}"] = $value;
        }

        return $server;
    }
}
