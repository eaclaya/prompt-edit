<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge REST API. Every non-2xx response or
 * transport failure becomes a PixelforgeException carrying the error code.
 */
final readonly class PixelforgeClient
{
    public function __construct(
        #[Config('pixelforge.base_url')]
        private string $baseUrl,
        #[Config('pixelforge.api_key')]
        private ?string $apiKey,
    ) {}

    /**
     * Submit a generation. The generation's ULID is the Idempotency-Key, so
     * retrying after a timeout returns the same Pixelforge generation instead
     * of creating (and billing) a second one.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(Generation $generation): GenerationPayload
    {
        $metadata = ['local_id' => $generation->id];

        if ($generation->user_id !== null) {
            $metadata['user_id'] = (string) $generation->user_id;
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $generation->id])
            ->post('/generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                'metadata' => $metadata,
            ]));

        return GenerationPayload::fromArray(
            $response->json(),
            replayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    /** @throws PixelforgeException */
    public function getGeneration(string $providerId): GenerationPayload
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($providerId)));

        return GenerationPayload::fromArray($response->json());
    }

    /**
     * Daily usage for this API key, by UTC completion day.
     *
     * @return array<string, mixed> the raw GET /v1/usage body
     *
     * @throws PixelforgeException
     */
    public function usage(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]))->json();
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws PixelforgeException
     */
    private function send(Closure $call): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }
}
