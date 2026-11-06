<?php

declare(strict_types=1);

namespace Videxo\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Videxo\Exception\WebhookVerificationException;
use Videxo\Model\WebhookEvent;
use Videxo\Webhook;

/**
 * Lets a webhook delivery through only when its signature matches. It reads at most
 * `videxo.webhook.max_body_bytes` of the raw body (413 above), verifies those exact bytes (400 when
 * they do not pass) and puts the event on the request: `$request->attributes->get(WebhookEvent::class)`.
 * Use it on a route of your own when you set `videxo.webhook.path` to null.
 */
final class VerifyWebhookSignature
{
    public function __construct(private readonly Webhook $webhook, private readonly Repository $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $setting = $this->config->get('videxo.webhook.max_body_bytes');
        $max = is_numeric($setting) ? (int) $setting : 2 * 1024 * 1024;
        $length = $request->headers->get('Content-Length');
        if (is_numeric($length) && (float) $length > $max) {
            return new Response('', 413);
        }
        $stream = $request->getContent(true);
        try {
            $payload = stream_get_contents($stream, $max + 1);
        } finally {
            fclose($stream);
        }
        if ($payload === false) {
            throw new \RuntimeException('Could not read the webhook body.');
        }
        if (strlen($payload) > $max) {
            return new Response('', 413);
        }
        try {
            $event = $this->webhook->verify($payload, $request->headers->all());
        } catch (WebhookVerificationException) {
            return new Response('', 400);
        }
        $request->attributes->set(WebhookEvent::class, $event);
        $response = $next($request);
        \assert($response instanceof Response);
        return $response;
    }
}
