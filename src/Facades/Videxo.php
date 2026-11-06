<?php

declare(strict_types=1);

namespace Videxo\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The Videxo client bound in the container: `Videxo::transcode([...])`, `Videxo::jobs()->wait($id)`.
 *
 * @method static \Videxo\Model\Job transcode(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Model\Job thumbnails(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Model\Job storyboard(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Model\Job gif(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Model\Job audio(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Model\Job concat(array<array-key, mixed> $body, ?string $idempotencyKey = null)
 * @method static \Videxo\Resource\Jobs jobs()
 * @method static \Videxo\Resource\Uploads uploads()
 * @method static \Videxo\Resource\Outputs outputs()
 * @method static \Videxo\Resource\Presets presets()
 * @method static \Videxo\Resource\Fonts fonts()
 *
 * @see \Videxo\Videxo
 */
final class Videxo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Videxo\Videxo::class;
    }
}
