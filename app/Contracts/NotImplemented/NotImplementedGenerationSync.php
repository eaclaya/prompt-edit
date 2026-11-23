<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\GenerationSync;
use App\Models\Generation;
use App\Pixelforge\Data\ProviderGeneration;

final class NotImplementedGenerationSync implements GenerationSync
{
    public function apply(ProviderGeneration $generation): ?Generation
    {
        throw NotImplementedException::for(GenerationSync::class, 'S5');
    }
}
