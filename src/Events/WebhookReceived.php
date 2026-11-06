<?php

declare(strict_types=1);

namespace Videxo\Laravel\Events;

use Videxo\Model\WebhookEvent;

/**
 * Every verified webhook delivery, of any type. `$event->id` is the delivery's webhook-id, the same
 * on each retry of one event: store it in a unique column to skip a repeat.
 */
final class WebhookReceived
{
    public function __construct(public readonly WebhookEvent $event)
    {
    }
}
