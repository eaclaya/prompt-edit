<?php

use Illuminate\Support\Facades\Route;

it('generates https URLs behind the Cloudflare tunnel', function () {
    Route::get('/_test/url', fn () => url('/generations'));

    $this->get('/_test/url', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'prompt-edit.hondukash.dev',
        'X-Forwarded-Port' => '443',
    ])->assertOk()->assertContent('https://prompt-edit.hondukash.dev/generations');
});
