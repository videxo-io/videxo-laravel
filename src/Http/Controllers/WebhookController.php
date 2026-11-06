<?php

declare(strict_types=1);

namespace Videxo\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Videxo\Laravel\Events\JobCancelled;
use Videxo\Laravel\Events\JobFailed;
use Videxo\Laravel\Events\JobSucceeded;
use Videxo\Laravel\Events\WebhookReceived;
use Videxo\Model\WebhookEvent;
use Videxo\Model\WebhookEventType;

/**
 * Dispatches a verified delivery as Laravel events (WebhookReceived for every one, then JobSucceeded,
 * JobFailed or JobCancelled) and answers 204. Keep listeners quick, or queue them with ShouldQueue:
 * Videxo waits 10 seconds for the answer and retries a delivery that fails.
 */
final class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $event = $request->attributes->get(WebhookEvent::class);
        if (!$event instanceof WebhookEvent) {
            throw new \LogicException('The Videxo webhook route needs the VerifyWebhookSignature middleware.');
        }
        $events->dispatch(new WebhookReceived($event));
        $job = $event->job;
        if ($job !== null) {
            $specific = match ($event->eventType()) {
                WebhookEventType::JobSucceeded => new JobSucceeded($job, $event),
                WebhookEventType::JobFailed => new JobFailed($job, $event),
                WebhookEventType::JobCancelled => new JobCancelled($job, $event),
                null => null,
            };
            if ($specific !== null) {
                $events->dispatch($specific);
            }
        }
        return new Response('', 204);
    }
}
