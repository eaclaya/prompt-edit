<?php

namespace App\Contracts;

use App\Models\Generation;
use App\Pixelforge\Data\ProviderGeneration;

/**
 * Implemented by S5 (app/Generations). The single path for every provider update:
 * the POST response, webhooks and the reconcile poller all call apply().
 */
interface GenerationSync
{
    /**
     * Match on metadata.local_id, then provider_id; lock the row; apply the update
     * only if the status moves forward; settle on success, release on failure.
     * Idempotent. Returns null when no local generation matches.
     */
    public function apply(ProviderGeneration $generation): ?Generation;
}
