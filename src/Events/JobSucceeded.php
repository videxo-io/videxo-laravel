<?php

declare(strict_types=1);

namespace Videxo\Laravel\Events;

use Videxo\Model\Job;
use Videxo\Model\WebhookEvent;

/**
 * A job succeeded (the `job.succeeded` webhook). The event serializes for queued
 * listeners. Its `event->id` is the delivery's webhook-id: skip one you already handled.
 */
final class JobSucceeded
{
    public function __construct(public readonly Job $job, public readonly WebhookEvent $event)
    {
    }
}
