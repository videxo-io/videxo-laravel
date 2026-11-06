<?php

declare(strict_types=1);

namespace Videxo\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Videxo\Laravel\Http\LaravelHttpClient;
use Videxo\Videxo;
use Videxo\Webhook;

/**
 * Binds the Videxo client and the webhook verifier from `config/videxo.php`, and registers the
 * webhook route. Laravel discovers it; nothing to add to `bootstrap/providers.php`.
 */
final class VidexoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/videxo.php', 'videxo');

        $this->app->singleton(Videxo::class, static function (Application $app): Videxo {
            $config = Config::from($app);
            $key = $config->string('api_key');
            if ($key === null) {
                throw new \InvalidArgumentException('Set VIDEXO_API_KEY in your .env: an API key from the dashboard.');
            }
            $timeout = $config->float('timeout', Videxo::DEFAULT_TIMEOUT);
            return new Videxo(
                $key,
                $config->string('base_url') ?? Videxo::DEFAULT_BASE_URL,
                httpClient: $config->string('http_client') === 'guzzle' ? null : new LaravelHttpClient($app->make(HttpFactory::class), $timeout),
                maxRetries: (int) $config->float('max_retries', Videxo::DEFAULT_MAX_RETRIES),
                timeout: $timeout,
            );
        });
        $this->app->alias(Videxo::class, 'videxo');

        $this->app->singleton(Webhook::class, static function (Application $app): Webhook {
            $config = Config::from($app);
            $secret = $config->string('webhook.secret');
            if ($secret === null) {
                throw new \InvalidArgumentException('Set VIDEXO_WEBHOOK_SECRET in your .env: the signing secret from the Webhooks page.');
            }
            return new Webhook($secret, (int) $config->float('webhook.tolerance', Webhook::TOLERANCE_SECONDS));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/videxo.php' => $this->app->configPath('videxo.php')], 'videxo-config');
        }
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

        // The webhook's body is read once, bounded, by its middleware: these must not read it first.
        $path = Config::from($this->app)->string('webhook.path');
        if ($path !== null && trim($path, '/') !== '') {
            $isWebhook = static fn (Request $request): bool => $request->is(trim($path, '/'));
            if (class_exists(TrimStrings::class)) {
                TrimStrings::skipWhen($isWebhook);
                ConvertEmptyStringsToNull::skipWhen($isWebhook);
            }
        }
    }
}
