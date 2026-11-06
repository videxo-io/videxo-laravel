<?php

declare(strict_types=1);

namespace Videxo\Laravel\Tests;

use Illuminate\Foundation\Application;
use Videxo\Laravel\VidexoServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected const KEY = 'vdx_test_key';

    protected const BASE_URL = 'https://api.videxo.test';

    protected string $secret;

    protected function setUp(): void
    {
        // Made at run time, so no file holds anything that looks like a signing secret.
        $this->secret = 'whsec_' . base64_encode(str_repeat('k', 32));
        parent::setUp();
    }

    protected function application(): Application
    {
        \assert($this->app !== null);
        return $this->app;
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [VidexoServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('videxo.api_key', self::KEY);
        $app['config']->set('videxo.base_url', self::BASE_URL);
        $app['config']->set('videxo.webhook.secret', $this->secret);
    }

    /**
     * A job as the API answers it.
     *
     * @return array<string, mixed>
     */
    protected static function job(string $state = 'queued'): array
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/api.json'), true, 512, JSON_THROW_ON_ERROR);
        \assert(is_array($fixtures) && is_array($fixtures['jobs']) && is_array($fixtures['jobs'][$state]));
        /** @var array<string, mixed> $job */
        $job = $fixtures['jobs'][$state];
        return $job;
    }
}
