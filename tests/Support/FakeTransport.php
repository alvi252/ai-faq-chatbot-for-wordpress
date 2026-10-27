<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Support;

use AIFAQ\Core\Http\HttpRequest;
use AIFAQ\Core\Http\HttpResponse;
use AIFAQ\Core\Http\TransportException;
use AIFAQ\Core\Http\TransportInterface;

/**
 * Records requests and replays queued responses. Never opens a socket.
 */
final class FakeTransport implements TransportInterface
{
    /** @var HttpRequest[] */
    public array $requests = [];

    /** @var array<int, HttpResponse|TransportException> */
    private array $queue = [];

    public function queue(HttpResponse|TransportException $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function queueJson(int $status, array $body, array $headers = []): self
    {
        return $this->queue(new HttpResponse($status, (string) json_encode($body), $headers));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if (null === $next) {
            throw new \LogicException('FakeTransport: no response queued.');
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $last = end($this->requests);

        return false === $last ? [] : (array) json_decode($last->body, true);
    }
}
