<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a webhook's signed timestamp may differ from our clock.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Our own budget per minute (the account limit is 60, shared by all keys).
    'requests_per_minute' => (int) env('PIXELFORGE_REQUESTS_PER_MINUTE', 50),

    'max_concurrent' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),

    // Markup on the provider cost, in basis points (2500 = 25%).
    'markup_bps' => (int) env('MARKUP_BPS', 2500),

    'welcome_credit_usd' => env('WELCOME_CREDIT_USD', '2.00'),

    /*
    | Fallback pricing, used only when GET /v1/pricing is unavailable.
    | Mirrors the published docs. Actual costs always use the rates stored
    | on each generation, never these values.
    */
    'fallback_pricing' => [
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
