<?php

/*
|--------------------------------------------------------------------------
| Pixelforge (https://pixelforge.promptedit.dev/docs)
|--------------------------------------------------------------------------
|
| Money values are decimal strings in US dollars, never floats. They are
| parsed into App\Support\Money (integer nanodollars) where they are used.
|
*/

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    // whsec_… signing secret of the API key whose webhook URL points at us.
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a webhook's signed timestamp may differ from now.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Keep our own copy of each image (Pixelforge's image_url expires 24 h after completion).
    'store_images' => (bool) env('PIXELFORGE_STORE_IMAGES', true),

    // HTTP client timeouts, in seconds.
    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 10),
    'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 5),

    // Account-wide limits from the docs; the submission funnel stays under them.
    'limits' => [
        'requests_per_minute' => (int) env('PIXELFORGE_REQUESTS_PER_MINUTE', 60),
        'max_concurrent' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),
    ],

    // Our markup on the provider cost, in basis points (2500 = 25%).
    'markup_bps' => (int) env('MARKUP_BPS', 2500),

    // Credit granted to every new user, as a decimal USD string.
    'welcome_credit' => (string) env('WELCOME_CREDIT_USD', '1.00'),

    // Fallback for GET /v1/pricing, which is cached for cache_ttl seconds.
    'pricing' => [
        'cache_ttl' => 3600,

        // USD per 1M tokens.
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],

        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ],

];
