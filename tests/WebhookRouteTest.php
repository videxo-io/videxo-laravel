<?php

declare(strict_types=1);

namespace Videxo\Laravel\Tests;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Videxo\Laravel\Http\Controllers\WebhookController;
use Videxo\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Videxo\Laravel\Events\JobCancelled;
use Videxo\Laravel\Events\JobFailed;
use Videxo\Laravel\Events\JobSucceeded;
use Videxo\Laravel\Events\WebhookReceived;
use Videxo\Model\JobState;

final class WebhookRouteTest extends TestCase
{
    /** The body of a delivery for a job in `$state`. */
    private static function payload(string $state = 'succeeded'): string
    {
        $job = self::job($state);
        return (string) json_encode(['type' => "job.{$state}", 'timestamp' => '2026-11-04T12:01:09.000Z', 'data' => $job], JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> */
    private function signed(string $payload, int $age = 0, string $id = 'msg_2k9x0c2mfq8w1n7r5t4b6v2z'): array
    {
        $timestamp = (string) (time() - $age);
        $key = (string) base64_decode(substr($this->secret, 6), true);
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true));
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => "v1,{$signature}",
        ];
    }

    /**
     * @param array<string, string> $server
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deliver(string $payload, array $server, string $path = '/webhooks/videxo'): TestResponse
    {
        return $this->call('POST', $path, [], [], [], $server, $payload);
    }

    public function testDispatchesTheEventsOfAVerifiedDelivery(): void
    {
        Event::fake();
        $payload = self::payload();
        $this->deliver($payload, $this->signed($payload))->assertNoContent();

        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $received) => $received->event->id === 'msg_2k9x0c2mfq8w1n7r5t4b6v2z');
        Event::assertDispatched(JobSucceeded::class, static fn (JobSucceeded $event) => $event->job->state === JobState::Succeeded
            && $event->job->outputs[0]->alias === 'out_video'
            && $event->event->type === 'job.succeeded');
        Event::assertNotDispatched(JobFailed::class);
    }

    public function testDispatchesFailedAndCancelledJobs(): void
    {
        Event::fake();
        $failed = self::payload('failed');
        $this->deliver($failed, $this->signed($failed))->assertNoContent();
        Event::assertDispatched(JobFailed::class, static fn (JobFailed $event) => $event->job->error?->code === 'input_unreachable');

        $job = self::job('failed');
        $job['state'] = 'cancelled';
        $job['error'] = null;
        $cancelled = (string) json_encode(['type' => 'job.cancelled', 'timestamp' => '2026-11-04T12:01:09.000Z', 'data' => $job]);
        $this->deliver($cancelled, $this->signed($cancelled))->assertNoContent();
        Event::assertDispatched(JobCancelled::class);
    }

    public function testRefusesABadSignatureWithoutEvents(): void
    {
        Event::fake([WebhookReceived::class, JobSucceeded::class]);
        $payload = self::payload();
        foreach (['v1', 'garbage', 'v1,c2lnbmF0dXJl'] as $signature) {
            $this->deliver($payload, ['HTTP_WEBHOOK_SIGNATURE' => $signature] + $this->signed($payload))->assertStatus(400);
        }
        $this->deliver($payload . ' ', $this->signed($payload))->assertStatus(400);
        $this->deliver($payload, $this->signed($payload, age: 301))->assertStatus(400);
        $server = $this->signed($payload);
        unset($server['HTTP_WEBHOOK_ID']);
        $this->deliver($payload, $server)->assertStatus(400);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(JobSucceeded::class);
    }

    public function testNeedsNoCsrfTokenOrSession(): void
    {
        $payload = self::payload();
        $this->deliver($payload, $this->signed($payload))->assertNoContent()->assertCookieMissing('XSRF-TOKEN');
        $route = Route::getRoutes()->getByName('videxo.webhook');
        self::assertNotNull($route);
        self::assertNotContains('web', $route->gatherMiddleware());
    }

    public function testRefusesABodyOverTheLimitBeforeVerifying(): void
    {
        Event::fake();
        $limit = 2 * 1024 * 1024;
        $exact = str_pad(self::payload(), $limit);
        $this->deliver($exact, $this->signed($exact))->assertNoContent();
        $over = $exact . ' ';
        $this->deliver($over, $this->signed($over))->assertStatus(413);
        // Without Content-Length (chunked), the bounded read still stops at the limit.
        $server = $this->signed($over);
        $this->call('POST', '/webhooks/videxo', [], [], [], $server + ['CONTENT_LENGTH' => ''], $over)->assertStatus(413);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function useOtherPath($app): void
    {
        $app['config']->set('videxo.webhook.path', '/hooks/videxo-events/');
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function useNoPath($app): void
    {
        $app['config']->set('videxo.webhook.path', null);
    }

    #[DefineEnvironment('useOtherPath')]
    public function testTakesItsPathFromTheConfig(): void
    {
        $payload = self::payload();
        $this->deliver($payload, $this->signed($payload), '/hooks/videxo-events')->assertNoContent();
        $this->deliver($payload, $this->signed($payload))->assertNotFound();
    }

    #[DefineEnvironment('useNoPath')]
    public function testRegistersNoRouteWithoutAPath(): void
    {
        self::assertNull(Route::getRoutes()->getByName('videxo.webhook'));
    }

    public function testAMissingSecretIsAServerError(): void
    {
        config(['videxo.webhook.secret' => null]);
        $this->application()->forgetInstance(\Videxo\Webhook::class);
        $this->withoutExceptionHandling();
        $this->expectExceptionMessage('Set VIDEXO_WEBHOOK_SECRET');
        $payload = self::payload();
        $this->deliver($payload, $this->signed($payload));
    }

    public function testQueuedListenersGetTheEvent(): void
    {
        Queue::fake();
        Event::listen(JobSucceeded::class, QueuedJobListener::class);
        $payload = self::payload();
        $this->deliver($payload, $this->signed($payload))->assertNoContent();
        Queue::assertPushed(CallQueuedListener::class, static function (CallQueuedListener $job): bool {
            $event = unserialize(serialize($job->data[0] ?? null));
            return $event instanceof JobSucceeded && $event->job->state === JobState::Succeeded && $event->event->id === 'msg_2k9x0c2mfq8w1n7r5t4b6v2z';
        });
    }
    /** @param \Illuminate\Foundation\Application $app */
    protected function useCustomRoute($app): void
    {
        $app['config']->set('videxo.webhook.path', null);
        $app['config']->set('videxo.webhook.raw_body_paths', ['hooks/custom-videxo']);
        $app['config']->set('videxo.webhook.max_body_bytes', 32);
    }

    /** @return iterable<string, array{bool}> */
    public static function contentLengths(): iterable
    {
        yield 'known length' => [true];
        yield 'unknown length' => [false];
    }

    #[DefineEnvironment('useCustomRoute')]
    #[DataProvider('contentLengths')]
    public function testCustomRouteSkipsGlobalNormalizationBeforeItsBodyCheck(bool $knownLength): void
    {
        Route::post('/hooks/custom-videxo', WebhookController::class)->middleware(VerifyWebhookSignature::class);
        Event::fake([WebhookReceived::class]);
        $payload = '{"value":"' . str_repeat('x', 64) . '"}';
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hooks/custom-videxo', 'CONTENT_TYPE' => 'application/json'];
        if ($knownLength) {
            $server['CONTENT_LENGTH'] = (string) strlen($payload);
        }
        $request = new class ([], [], [], [], [], $server, $payload) extends Request {
            public bool $decoded = false;

            /**
             * @param string|null $key
             * @param mixed $default
             * @return ($key is null ? InputBag<string|int|float|bool|null> : mixed)
             */
            public function json($key = null, $default = null)
            {
                $this->decoded = true;
                return parent::json($key, $default);
            }
        };
        $kernel = $this->application()->make(\Illuminate\Contracts\Http\Kernel::class);
        self::assertInstanceOf(Kernel::class, $kernel);
        $global = $kernel->getGlobalMiddleware();
        $trim = array_search(TrimStrings::class, $global, true);
        $empty = array_search(ConvertEmptyStringsToNull::class, $global, true);
        self::assertIsInt($trim);
        self::assertIsInt($empty);
        self::assertLessThan($empty, $trim);
        self::assertSame(413, $kernel->handle($request)->getStatusCode());
        self::assertFalse($request->decoded, 'Global normalization decoded an unauthenticated body before its size check.');
        Event::assertNotDispatched(WebhookReceived::class);
    }

    #[DefineEnvironment('useCustomRoute')]
    public function testCustomWebhookExclusionsKeepOtherRoutesNormalized(): void
    {
        Route::post('/normalize', static fn (Request $request) => response()->json($request->only(['trim', 'empty'])));
        $this->postJson('/normalize', ['trim' => ' value ', 'empty' => ''])->assertExactJson(['trim' => 'value', 'empty' => null]);
    }
    /** @param \Illuminate\Foundation\Application $app */
    protected function useCapturedCustomRoute($app): void
    {
        $app['config']->set('videxo.webhook.path', null);
        $app['config']->set('videxo.webhook.raw_body_paths', ['hooks/captured-videxo']);
    }

    /** @return iterable<string, array{string}> */
    public static function captureFramings(): iterable
    {
        yield 'Content-Length' => ['known'];
        yield 'unknown length' => ['unknown'];
        yield 'chunked' => ['chunked'];
    }

    #[DataProvider('captureFramings')]
    public function testAutomaticWebhookCaptureDecodesBeforeThePackageLimit(string $framing): void
    {
        $this->assertCapturePrecedesPackageLimit('/webhooks/videxo', $framing);
    }

    #[DefineEnvironment('useCapturedCustomRoute')]
    #[DataProvider('captureFramings')]
    public function testCustomWebhookCaptureDecodesBeforeThePackageLimit(string $framing): void
    {
        Route::post('/hooks/captured-videxo', WebhookController::class)->middleware(VerifyWebhookSignature::class);
        $this->assertCapturePrecedesPackageLimit('/hooks/captured-videxo', $framing);
    }

    /**
     * Qualifies framework capture only; no upstream server is exercised or qualified here.
     * Framing cases supply capture metadata, not real on-wire chunked requests.
     * The earlier direct-kernel tests qualify the later global-normalization exclusions.
     */
    private function assertCapturePrecedesPackageLimit(string $path, string $framing): void
    {
        $limit = config('videxo.webhook.max_body_bytes');
        self::assertSame(2 * 1024 * 1024, $limit);
        $prefix = '{"value":"';
        $suffix = '"}';
        $value = str_repeat('x', $limit + 1 - strlen($prefix . $suffix));
        $payload = $prefix . $value . $suffix;
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($framing === 'known') {
            $server['CONTENT_LENGTH'] = (string) strlen($payload);
        } elseif ($framing === 'chunked') {
            $server['HTTP_TRANSFER_ENCODING'] = 'chunked';
        }
        $base = SymfonyRequest::create($path, 'POST', [], [], [], $server, $payload);
        if ($framing !== 'known') {
            $base->headers->remove('Content-Length');
            $base->server->remove('CONTENT_LENGTH');
        }
        $prototype = new class extends Request {
            public int $jsonReads = 0;

            /**
             * @param string|null $key
             * @param mixed $default
             * @return ($key is null ? InputBag<string|int|float|bool|null> : mixed)
             */
            public function json($key = null, $default = null)
            {
                $this->jsonReads++;
                return parent::json($key, $default);
            }
        };
        // capture() delegates to this actual framework method before calling the HTTP kernel.
        $request = $prototype::createFromBase($base);
        self::assertSame($limit + 1, strlen($payload));
        self::assertSame(1, $request->jsonReads, 'Framework capture already decoded this oversized, unauthenticated JSON.');
        self::assertSame($value, $request->request->get('value'));
        Event::fake([WebhookReceived::class]);
        $kernel = $this->application()->make(\Illuminate\Contracts\Http\Kernel::class);
        self::assertSame(413, $kernel->handle($request)->getStatusCode());
        self::assertSame(1, $request->jsonReads, 'The exclusions prevent later normalization, not the earlier capture.');
        Event::assertNotDispatched(WebhookReceived::class);
    }
}
