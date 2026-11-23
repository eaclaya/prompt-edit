<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Owned by S4. Sends one local generation to Pixelforge. */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public function __construct(public Generation $generation) {}

    public function handle(): void {}
}
