<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Videxo\Laravel\Http\Controllers\WebhookController;
use Videxo\Laravel\Http\Middleware\VerifyWebhookSignature;

$path = config('videxo.webhook.path');
$middleware = config('videxo.webhook.middleware');

if (is_string($path) && trim($path, '/') !== '') {
    Route::post(trim($path, '/'), WebhookController::class)
        ->middleware([...array_filter(is_array($middleware) ? $middleware : [], 'is_string'), VerifyWebhookSignature::class])
        ->name('videxo.webhook');
}
