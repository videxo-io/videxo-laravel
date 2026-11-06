<?php

declare(strict_types=1);

namespace Videxo\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Typed reads of `config('videxo.*')`.
 *
 * @internal
 */
final class Config
{
    private function __construct(private readonly Repository $config)
    {
    }

    public static function from(Container $app): self
    {
        return new self($app->make(Repository::class));
    }

    /** A setting that is a string with something in it, or null. */
    public function string(string $key): ?string
    {
        $value = $this->config->get("videxo.{$key}");
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->config->get("videxo.{$key}");
        return is_numeric($value) ? (float) $value : $default;
    }
}
