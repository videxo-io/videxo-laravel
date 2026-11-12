<?php

declare(strict_types=1);

return [
    // An API key from the dashboard's API keys page. Keep it in .env, never in code.
    'api_key' => env('VIDEXO_API_KEY'),

    // The API's address; set VIDEXO_API_URL only for a local or test API.
    'base_url' => env('VIDEXO_API_URL') ?: 'https://api.videxo.io',

    // How requests are sent: `laravel` through the Http client, so Http::fake() and
    // Http::preventStrayRequests() cover them, or `guzzle` through a plain Guzzle client.
    'http_client' => env('VIDEXO_HTTP_CLIENT', 'laravel'),

    // Retries of a failed request (429, 502, 503, 504, a network error or timeout).
    'max_retries' => (int) env('VIDEXO_MAX_RETRIES', 2),

    // Seconds one attempt may take.
    'timeout' => (float) env('VIDEXO_TIMEOUT', 60),

    'webhook' => [
        // The signing secret from the dashboard's Webhooks page.
        'secret' => env('VIDEXO_WEBHOOK_SECRET'),

        // Where Videxo posts webhooks. Set to null to register the route yourself.
        'path' => env('VIDEXO_WEBHOOK_PATH', 'webhooks/videxo'),

        // Paths of manually registered webhook routes. Their raw bodies must reach the size and
        // signature checks before Laravel's global JSON normalization. The automatic path above
        // is excluded already; list custom paths here when path is null.
        'raw_body_paths' => [],

        // Middleware in front of the signature check, such as a rate limiter. The route has no
        // session or CSRF check: the signature authenticates it.
        'middleware' => [],

        // Bodies over this many bytes are refused with 413 before the signature check.
        'max_body_bytes' => 2 * 1024 * 1024,

        // Seconds a delivery's timestamp may be from now.
        'tolerance' => 300,
    ],
];
