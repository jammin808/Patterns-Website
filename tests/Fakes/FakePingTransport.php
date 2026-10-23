<?php

declare(strict_types=1);

namespace Psc\Tests\Fakes;

use Psc\News\PingTransport;

/**
 * The announcer's way out in tests: every POST is recorded (its address, body and type) and answered from the queue of
 * statuses, then with $status once the queue is empty. Nothing reaches the network.
 */
final class FakePingTransport implements PingTransport
{
    /** @var list<array{url: string, body: string, type: string}> */
    public array $posts = [];

    /** @var list<int> the statuses the next posts answer, in order */
    public array $answers = [];

    public function __construct(public int $status = 200)
    {
    }

    public function post(string $url, string $body, string $type): int
    {
        $this->posts[] = ['url' => $url, 'body' => $body, 'type' => $type];
        return $this->answers !== [] ? array_shift($this->answers) : $this->status;
    }

    /** @return list<array{url: string, body: string, type: string}> the posts to an address on $host */
    public function to(string $host): array
    {
        return array_values(array_filter($this->posts, static fn (array $p): bool => parse_url($p['url'], PHP_URL_HOST) === $host));
    }
}
