<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Pixelforge\Data\ProviderGeneration;
use App\Pixelforge\Data\ProviderGenerationPage;
use App\Pixelforge\Data\ProviderPricing;
use App\Pixelforge\Data\ProviderUsage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP layer over the Pixelforge API. No retries, no business rules:
 * callers decide what to do with each typed exception.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 10,
        private readonly int $connectTimeout = 5,
    ) {}

    /**
     * POST /v1/generations. Send only prompt, size, quality, reference_images and metadata
     * (unknown parameters are rejected with 422).
     *
     * @param  array<string, mixed>  $params
     */
    public function createGeneration(array $params, string $idempotencyKey): ProviderGeneration
    {
        $response = $this->send(fn () => $this->authenticated()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));

        return ProviderGeneration::fromArray(
            $this->json($response),
            idempotentReplayed: strtolower($response->header('Idempotent-Replayed')) === 'true',
        );
    }

    /** GET /v1/generations/{id}. */
    public function getGeneration(string $id): ProviderGeneration
    {
        return ProviderGeneration::fromArray($this->json(
            $this->send(fn () => $this->authenticated()->get('generations/'.rawurlencode($id))),
        ));
    }

    /** GET /v1/generations, newest first. */
    public function listGenerations(int $limit = 20, ?GenerationStatus $status = null, ?string $cursor = null): ProviderGenerationPage
    {
        $query = array_filter([
            'limit' => $limit,
            'status' => $status?->value,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null);

        return ProviderGenerationPage::fromArray($this->json(
            $this->send(fn () => $this->authenticated()->get('generations', $query)),
        ));
    }

    /** GET /v1/pricing (no authentication needed). */
    public function getPricing(): ProviderPricing
    {
        return ProviderPricing::fromArray($this->json(
            $this->send(fn () => $this->request()->get('pricing')),
        ));
    }

    /**
     * GET /v1/usage, per UTC day of completion. Dates are YYYY-MM-DD (UTC), inclusive, at most 92 days.
     */
    public function getUsage(string $start, string $end): ProviderUsage
    {
        return ProviderUsage::fromArray($this->json(
            $this->send(fn () => $this->authenticated()->get('usage', ['start_date' => $start, 'end_date' => $end])),
        ));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    private function authenticated(): PendingRequest
    {
        return $this->request()->withToken((string) $this->apiKey);
    }

    /** @param  callable(): Response  $request */
    private function send(callable $request): Response
    {
        return $request()->throw();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
