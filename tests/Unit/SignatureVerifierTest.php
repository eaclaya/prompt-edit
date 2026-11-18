<?php

use App\Pixelforge\SignatureVerifier;

beforeEach(function () {
    $this->verifier = new SignatureVerifier(secret: 'whsec_abc', toleranceSeconds: 300);
    $this->body = '{"id":"evt_1","type":"generation.succeeded"}';
    $this->now = 1_795_000_000;
});

function signatureHeader(int $t, string ...$signatures): string
{
    return "t={$t},".implode(',', array_map(fn ($s) => "v1={$s}", $signatures));
}

it('accepts a valid signature', function () {
    $signature = SignatureVerifier::sign($this->now, $this->body, 'whsec_abc');

    expect($this->verifier->isValid(signatureHeader($this->now, $signature), $this->body, $this->now))->toBeTrue();
});

it('accepts any matching v1 signature (secret rotation)', function () {
    $signature = SignatureVerifier::sign($this->now, $this->body, 'whsec_abc');

    expect($this->verifier->isValid(signatureHeader($this->now, str_repeat('f', 64), $signature), $this->body, $this->now))->toBeTrue();
});

it('accepts timestamps within the tolerance either way', function (int $skew) {
    $t = $this->now + $skew;

    expect($this->verifier->isValid(signatureHeader($t, SignatureVerifier::sign($t, $this->body, 'whsec_abc')), $this->body, $this->now))->toBeTrue();
})->with([-300, 300]);

it('rejects timestamps outside the tolerance', function (int $skew) {
    $t = $this->now + $skew;

    expect($this->verifier->isValid(signatureHeader($t, SignatureVerifier::sign($t, $this->body, 'whsec_abc')), $this->body, $this->now))->toBeFalse();
})->with([-301, 301]);

it('rejects a modified body', function () {
    $signature = SignatureVerifier::sign($this->now, $this->body, 'whsec_abc');

    expect($this->verifier->isValid(signatureHeader($this->now, $signature), $this->body.' ', $this->now))->toBeFalse();
});

it('rejects malformed or missing headers', function (?string $header) {
    expect($this->verifier->isValid($header, $this->body, $this->now))->toBeFalse();
})->with([null, '', 'garbage', 't=abc,v1=123', 'v1=abc', 't=1795000000']);

it('rejects everything when no secret is configured', function () {
    $verifier = new SignatureVerifier(secret: null, toleranceSeconds: 300);
    $signature = SignatureVerifier::sign($this->now, $this->body, '');

    expect($verifier->isValid(signatureHeader($this->now, $signature), $this->body, $this->now))->toBeFalse();
});
