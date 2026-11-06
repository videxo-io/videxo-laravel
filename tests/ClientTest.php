<?php

declare(strict_types=1);

namespace Videxo\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Videxo\Exception\ConnectionException;
use Videxo\Exception\NotFoundException;
use Videxo\Laravel\Facades\Videxo as VidexoFacade;
use Videxo\Model\JobState;
use Videxo\Videxo;

final class ClientTest extends TestCase
{
    public function testBindsOneClientFromTheConfig(): void
    {
        $videxo = $this->application()->make(Videxo::class);
        self::assertSame($videxo, $this->application()->make(Videxo::class));
        self::assertSame($videxo, $this->application()->make('videxo'));
        self::assertSame(self::BASE_URL, $videxo->baseUrl);
    }

    public function testTheFacadeSendsThroughLaravelsHttpClient(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL . '/v1/transcode' => Http::response(self::job(), 202)]);

        $job = VidexoFacade::transcode(['input' => 'https://example.com/talk.mp4', 'preset' => 'web-720p'], 'order-42');

        self::assertSame(JobState::Queued, $job->state);
        Http::assertSent(static fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE_URL . '/v1/transcode'
            && $request->header('Authorization') === ['Bearer ' . self::KEY]
            && $request->header('Idempotency-Key') === ['order-42']
            && $request->data() === ['input' => 'https://example.com/talk.mp4', 'preset' => 'web-720p']);
    }

    public function testResourcesWorkThroughTheFacade(): void
    {
        Http::fake([
            self::BASE_URL . '/v1/jobs/*' => Http::sequence()->push(self::job('running'))->push(self::job('succeeded')),
        ]);
        $id = self::job()['id'];
        \assert(is_string($id));
        $done = VidexoFacade::jobs()->wait($id, interval: 0.01);
        self::assertSame(JobState::Succeeded, $done->state);
        Http::assertSentCount(2);
    }

    public function testTheDownloadLinkIsReadFromTheRedirect(): void
    {
        Http::fake([self::BASE_URL . '/v1/jobs/job_1/outputs/out_video' => Http::response('', 302, ['Location' => 'https://files.videxo.test/x?s=1'])]);
        self::assertSame('https://files.videxo.test/x?s=1', VidexoFacade::outputs()->url('job_1', 'out_video'));
    }

    public function testErrorsAreTheSdksExceptions(): void
    {
        Http::fake([self::BASE_URL . '/*' => Http::response([
            'type' => 'https://videxo.io/docs/errors#not_found',
            'title' => 'Not found.',
            'status' => 404,
            'detail' => 'No job job_x.',
            'code' => 'not_found',
            'reason' => 'job',
            'retryable' => false,
            'request_id' => 'req_7h2m4x9q0c3v8k1n5r6t2b4z',
        ], 404, ['Content-Type' => 'application/problem+json'])]);
        try {
            VidexoFacade::jobs()->get('job_x');
            self::fail('no exception');
        } catch (NotFoundException $error) {
            self::assertSame('job', $error->reason);
            self::assertSame('req_7h2m4x9q0c3v8k1n5r6t2b4z', $error->requestId);
        }
    }

    public function testAConnectionFailureIsANetworkError(): void
    {
        config(['videxo.max_retries' => 0]);
        $this->application()->forgetInstance(Videxo::class);
        VidexoFacade::clearResolvedInstances();
        Http::fake(static fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect'));
        try {
            VidexoFacade::fonts()->list();
            self::fail('no exception');
        } catch (ConnectionException $error) {
            self::assertSame('network_error', $error->getErrorCode());
        }
    }

    public function testNeedsAnApiKey(): void
    {
        config(['videxo.api_key' => '']);
        $this->application()->forgetInstance(Videxo::class);
        $this->expectExceptionMessage('Set VIDEXO_API_KEY');
        $this->application()->make(Videxo::class);
    }

    public function testCanUseAPlainGuzzleClient(): void
    {
        config(['videxo.http_client' => 'guzzle']);
        $this->application()->forgetInstance(Videxo::class);
        self::assertInstanceOf(Videxo::class, $this->application()->make(Videxo::class));
    }

    public function testPublishesTheConfig(): void
    {
        $target = $this->application()->configPath('videxo.php');
        @unlink($target);
        self::assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'videxo-config']));
        self::assertFileExists($target);
        self::assertStringContainsString("env('VIDEXO_API_KEY')", (string) file_get_contents($target));
        unlink($target);
    }
}
