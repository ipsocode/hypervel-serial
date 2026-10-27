<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Testing;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Testing\Fakes\Fake;
use Ipsocode\Serial\Connection;
use Ipsocode\Serial\Contracts\Command;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use Ipsocode\Serial\SerialManager;
use Ipsocode\Serial\Transports\MemoryTransport;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The serial manager `Serial::fake()` puts in place: every configured connection sends to the
 * given simulator or callback instead of to its device, and records what it sent.
 */
class SerialFake extends SerialManager implements Fake
{
    /**
     * The requests sent, in order, with the name of the connection each was sent on.
     *
     * @var array<int, array{connection: string, request: Request}>
     */
    protected array $sent = [];

    /**
     * The callback that answers each request.
     *
     * @var Closure(Request, string): Result
     */
    protected Closure $responder;

    /**
     * Create a new serial fake.
     *
     * @param null|(Closure(Request, string): Result)|Simulator $responder what answers each request; without one, every request succeeds with an empty reply
     */
    public function __construct(Application $app, Closure|Simulator|null $responder = null)
    {
        parent::__construct($app);

        $this->responder = match (true) {
            $responder instanceof Simulator => fn (Request $request): Result => $responder->respond($request),
            $responder instanceof Closure => $responder,
            default => fn (Request $request): Result => new Response(new Frame('')),
        };
    }

    /**
     * Assert that a matching request was sent, or was sent the given number of times.
     *
     * @param (Closure(Request, string): bool)|Command $request
     */
    public function assertSent(Closure|Command $request, ?int $times = null): void
    {
        $count = count($this->sent($request));

        if ($times === null) {
            PHPUnit::assertGreaterThan(0, $count, 'The expected serial request was not sent.');

            return;
        }

        PHPUnit::assertSame($times, $count, "The expected serial request was sent {$count} times instead of {$times} times.");
    }

    /**
     * Assert that no matching request was sent.
     *
     * @param (Closure(Request, string): bool)|Command $request
     */
    public function assertNotSent(Closure|Command $request): void
    {
        PHPUnit::assertSame([], $this->sent($request), 'An unexpected serial request was sent.');
    }

    /**
     * Assert that no request was sent.
     */
    public function assertNothingSent(): void
    {
        PHPUnit::assertSame([], $this->sent, 'Serial requests were sent unexpectedly.');
    }

    /**
     * Assert the number of requests sent.
     */
    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->sent, 'The number of serial requests sent does not match.');
    }

    /**
     * Get the requests sent, or those that match.
     *
     * @param null|(Closure(Request, string): bool)|Command $request
     * @return array<int, Request>
     */
    public function sent(Closure|Command|null $request = null): array
    {
        $matches = match (true) {
            $request === null => fn (): bool => true,
            $request instanceof Command => fn (Request $sent): bool => $sent->command === $request,
            default => $request,
        };

        $sent = array_filter($this->sent, fn (array $sent): bool => $matches($sent['request'], $sent['connection']));

        return array_values(array_map(fn (array $sent): Request => $sent['request'], $sent));
    }

    /**
     * Resolve the given connection, which answers from the fake instead of its device.
     */
    protected function resolve(string $name): Connection
    {
        $config = $this->getInstanceConfig($name);

        $protocol = new SimulatedProtocol($this->app->make($config['protocol']), function (Request $request) use ($name): Result {
            $this->sent[] = ['connection' => $name, 'request' => $request];

            return ($this->responder)($request, $name);
        });

        return $this->createConnection($name, [...$config, 'lock' => null], new MemoryTransport, $protocol);
    }
}
