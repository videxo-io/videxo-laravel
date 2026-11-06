<?php

declare(strict_types=1);

namespace Videxo\Laravel\Tests;

use Illuminate\Contracts\Queue\ShouldQueue;
use Videxo\Laravel\Events\JobSucceeded;

/** A listener the queue runs, for WebhookRouteTest. */
final class QueuedJobListener implements ShouldQueue
{
    public function handle(JobSucceeded $event): void
    {
    }
}
