# Videxo for Laravel

The [Videxo](https://videxo.io) API in Laravel 12 and 13: hosted FFmpeg jobs over HTTP. This package
adds to [`videxo/videxo-php`](https://github.com/videxo-io/videxo-php) a service provider, a config
file, a `Videxo` facade, and a webhook route that checks each delivery's signature and dispatches
Laravel events. PHP 8.2 or newer.

```bash
composer require videxo/laravel
```

Laravel discovers the provider and the facade. Add the secrets to `.env`:

```dotenv
VIDEXO_API_KEY=
VIDEXO_WEBHOOK_SECRET=
```

The API key comes from the dashboard's **API keys** page and the signing secret from its
**Webhooks** page. Keep both on the server. `php artisan vendor:publish --tag=videxo-config` copies
`config/videxo.php` into your app when you want to change a setting.

## Call the API

```php
use Videxo\Laravel\Facades\Videxo;

$job = Videxo::transcode(['input' => 'https://example.com/talk.mp4', 'preset' => 'web-720p']);
$done = Videxo::jobs()->wait($job->id);
$url = Videxo::outputs()->url($done->id, 'video');
```

Or inject `Videxo\Videxo`, the same client. The
[PHP SDK's docs](https://videxo.io/docs/sdk/php) cover every call, its typed answers and its
exceptions; `jobs`, `uploads`, `outputs`, `presets` and `fonts` are methods on the facade.

Requests go through Laravel's Http client, so `Http::fake()` and `Http::preventStrayRequests()` cover
them in your tests. Set `VIDEXO_HTTP_CLIENT=guzzle` to send them with a plain Guzzle client instead.

## Create jobs from a queue

Pass your own idempotency key, so a retried queued job gets the same Videxo job back for 24 hours:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Videxo\Videxo;

final class TranscodeOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $orderId, public string $input)
    {
    }

    public function handle(Videxo $videxo): void
    {
        $job = $videxo->transcode(['input' => $this->input, 'preset' => 'web-720p'], "order-{$this->orderId}");
        // Store $job->id on the order, so your webhook listener can find it.
    }
}
```

## Receive webhooks

Both the automatic route and every custom webhook route require a trusted HTTP server or reverse
proxy to enforce a body limit before forwarding the request to PHP/Laravel. With the default
`webhook.max_body_bytes`, reject bodies over **2 MiB (2,097,152 bytes)**. Enforce actual received
bytes, including unknown-length and chunked requests; checking `Content-Length` alone is
insufficient. If you change the package limit, set the upstream limit to the same value or lower.

Laravel's `Request::capture()` can decode JSON through `createFromBase()` before the HTTP kernel
and service providers run. The package's middleware limit is defense in depth after capture; it
cannot prevent capture's earlier allocation. Verify that oversized requests are rejected upstream
before they reach Laravel, for both automatic and custom paths and each of these body framings.

The package registers `POST /webhooks/videxo`. Set that URL (your app's public `https` address plus
the path) as the endpoint on the dashboard's **Webhooks** page, or as a job's `webhook_url`. The route
has no session or CSRF check: the signature authenticates it. It answers:

| Status | When |
|---|---|
| 204 | The delivery is verified and its events were dispatched. |
| 400 | The signature, the timestamp or the body did not pass. No event is dispatched. |
| 413 | The package body limit is exceeded after capture, before signature verification. |

Listen to the events:

| Event | When | Properties |
|---|---|---|
| `Videxo\Laravel\Events\JobSucceeded` | `job.succeeded` | `job`, `event` |
| `Videxo\Laravel\Events\JobFailed` | `job.failed` | `job`, `event` |
| `Videxo\Laravel\Events\JobCancelled` | `job.cancelled` | `job`, `event` |
| `Videxo\Laravel\Events\WebhookReceived` | every delivery | `event` |

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Videxo\Laravel\Events\JobSucceeded;

final class StoreOutputs implements ShouldQueue
{
    public function handle(JobSucceeded $delivery): void
    {
        // $delivery->job is a Videxo\Model\Job; $delivery->event->id is the delivery's webhook-id.
    }
}
```

Laravel finds a listener in `app/Listeners` by itself. The events serialize, so a listener can
implement `ShouldQueue`, and it should when its work is slow: Videxo waits 10 seconds for the answer
and sends a failed delivery again later.

A delivery can arrive more than once with the same `$delivery->event->id`. Put it in a unique column,
in the same transaction as your update, and skip one you already handled.

### Your own route

Set `VIDEXO_WEBHOOK_PATH` to another path. To register the route yourself, set `webhook.path`
to `null` and `webhook.raw_body_paths` to `['hooks/videxo']` in `config/videxo.php`. The listed
custom path skips Laravel's later global `TrimStrings` and `ConvertEmptyStringsToNull`
normalization. These exclusions do not stop JSON decoding during capture and do not replace the
required upstream body limit above. Keep the route outside the `web` group:

```php
use Videxo\Laravel\Http\Controllers\WebhookController;
use Videxo\Laravel\Http\Middleware\VerifyWebhookSignature;

Route::post('/hooks/videxo', WebhookController::class)->middleware(VerifyWebhookSignature::class);
```

The middleware also works on a controller of your own: it puts the verified event on the request as
`$request->attributes->get(Videxo\Model\WebhookEvent::class)`.

## Settings

| Setting | `.env` | Default |
|---|---|---|
| `api_key` | `VIDEXO_API_KEY` | none; a call without it throws |
| `base_url` | `VIDEXO_API_URL` | `https://api.videxo.io` |
| `http_client` | `VIDEXO_HTTP_CLIENT` | `laravel` |
| `max_retries` | `VIDEXO_MAX_RETRIES` | `2` |
| `timeout` | `VIDEXO_TIMEOUT` | `60` seconds per attempt |
| `webhook.secret` | `VIDEXO_WEBHOOK_SECRET` | none; a delivery without it is a server error |
| `webhook.path` | `VIDEXO_WEBHOOK_PATH` | `webhooks/videxo` |
| `webhook.raw_body_paths` | | `[]`; paths of manually registered webhook routes |
| `webhook.middleware` | | none |
| `webhook.max_body_bytes` | | 2 MiB (2,097,152 bytes); defense in depth after capture |
| `webhook.tolerance` | | `300` seconds |

## License

MIT, see [LICENSE](LICENSE). Issues are welcome; see [CONTRIBUTING](CONTRIBUTING.md).
