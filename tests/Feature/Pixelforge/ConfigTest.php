<?php

it('reads the Pixelforge settings from the environment', function () {
    expect(config('pixelforge.base_url'))->toBe('https://pixelforge.test/v1')
        ->and(config('pixelforge.api_key'))->toBe('pf_test_key')
        ->and(config('pixelforge.webhook_secret'))->toBe('whsec_test_secret')
        ->and(config('pixelforge.webhook_tolerance'))->toBe(300)
        ->and(config('pixelforge.markup_bps'))->toBe(2500)
        ->and(config('pixelforge.welcome_credit'))->toBe('1.00');
});

it('has rate limits and HTTP timeouts', function () {
    expect(config('pixelforge.limits'))->toBe([
        'requests_per_minute' => 60,
        'max_concurrent' => 3,
    ])
        ->and(config('pixelforge.timeout'))->toBeInt()
        ->and(config('pixelforge.connect_timeout'))->toBeInt();
});

it('falls back to the documented pricing when the endpoint is unavailable', function () {
    expect(config('pixelforge.pricing.rates'))->toBe([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ])
        ->and(config('pixelforge.pricing.tokens.per_reference_image'))->toBe(765)
        ->and(config('pixelforge.pricing.tokens.output.high.1536x1024'))->toBe(6450)
        ->and(config('pixelforge.pricing.tokens.reasoning.high'))->toBe(['min' => 1000, 'max' => 8000])
        ->and(config('pixelforge.pricing.cache_ttl'))->toBe(3600);
});
