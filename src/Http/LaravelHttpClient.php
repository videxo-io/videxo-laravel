<?php

declare(strict_types=1);

namespace Videxo\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client over Laravel's Http client, so `Http::fake()`, `Http::preventStrayRequests()` and
 * the client's events cover the SDK's requests. It follows no redirects and throws for no status:
 * the SDK reads every answer and retries itself.
 */
final class LaravelHttpClient implements ClientInterface
{
    public function __construct(private readonly Factory $http, private readonly float $timeout = 60.0)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $pending = $this->http->createPendingRequest()
            ->withoutRedirecting()
            ->timeout($this->timeout)
            ->connectTimeout(min(10.0, $this->timeout))
            ->withHeaders($headers);
        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/octet-stream');
        }
        try {
            return $pending->send($request->getMethod(), (string) $request->getUri())->toPsrResponse();
        } catch (ConnectionException $error) {
            throw new class ($error->getMessage(), $request, $error) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request, \Throwable $previous)
                {
                    parent::__construct($message, 0, $previous);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }
    }
}
