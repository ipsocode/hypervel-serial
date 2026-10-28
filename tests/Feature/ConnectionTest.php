<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature;

use Closure;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Connection;
use Ipsocode\Serial\Events\FrameReceived;
use Ipsocode\Serial\Events\FrameSent;
use Ipsocode\Serial\Events\TransactionFailed;
use Ipsocode\Serial\Exceptions\ReservedException;
use Ipsocode\Serial\Exceptions\TimeoutException;
use Ipsocode\Serial\Exceptions\TransportException;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\RetryPolicy;
use Ipsocode\Serial\Sequences\Counter;
use Ipsocode\Serial\Testing\FakeTransport;
use Ipsocode\Serial\Tests\Fixtures\HeaderProtocol;
use Ipsocode\Serial\Tests\Fixtures\StatusResult;
use Ipsocode\Serial\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

class ConnectionTest extends TestCase
{
    protected HeaderProtocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protocol = new HeaderProtocol;
    }

    public function testTransactOpensTheDeviceSendsTheRequestAndReturnsTheReply(): void
    {
        $transport = new FakeTransport([$this->reply(0, 'abc')]);

        $result = $this->connection($transport)->transact(new Request(6, 'xy', 1));

        $this->assertTrue($transport->isOpen());
        $this->assertSame([$this->protocol->encode((new Request(6, 'xy', 1))->withSequence(0))], $transport->written());
        $this->assertInstanceOf(StatusResult::class, $result);
        $this->assertTrue($result->success());
        $this->assertSame('abc', $result->data());
    }

    public function testTransactNumbersRequestsInSequence(): void
    {
        $transport = new FakeTransport([$this->echo(), $this->echo()]);
        $connection = $this->connection($transport);

        $connection->transact(new Request(6));
        $connection->transact(new Request(6));

        $this->assertSame([0, 1], array_map($this->sequenceOf(...), $transport->written()));
    }

    public function testTransactDiscardsBytesThatArrivedBeforeTheRequest(): void
    {
        $transport = new FakeTransport([$this->reply(41, 'stale'), $this->echo()]);
        $connection = $this->connection($transport);
        $transport->open();
        $transport->write('noise');

        $this->assertTrue($connection->transact(new Request(6), retries: 0)->success());
    }

    public function testTransactReadsAReplyThatArrivesInPieces(): void
    {
        Sleep::fake();

        $transport = new FakeTransport([$this->reply(0, 'a longer payload')], chunkSize: 3);

        $this->assertSame('a longer payload', $this->connection($transport)->transact(new Request(6))->data());
    }

    public function testTransactTriesAgainAfterAReplyThatDoesNotMatchItsChecksum(): void
    {
        Sleep::fake();

        $corrupt = substr_replace($this->reply(0), 'F', -3, 1);
        $transport = new FakeTransport([$corrupt, $this->echo()]);

        $this->assertTrue($this->connection($transport)->transact(new Request(6))->success());
        $this->assertCount(2, $transport->written());
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 100.0, 1);
    }

    public function testTransactTriesAgainAfterAReplyToAnotherRequest(): void
    {
        Sleep::fake();

        $transport = new FakeTransport([$this->reply(7), $this->echo()]);

        $this->assertTrue($this->connection($transport)->transact(new Request(6))->success());
        $this->assertSame([0, 0], array_map($this->sequenceOf(...), $transport->written()));
    }

    public function testTransactSkipsALateReplyToAnEarlierRequestAndReturnsItsOwn(): void
    {
        $transport = new FakeTransport([
            fn (string $written): string => $this->reply(7, 'late') . $this->reply($this->sequenceOf($written), 'own'),
        ]);

        $result = $this->connection($transport)->transact(new Request(6), retries: 0);

        $this->assertSame('own', $result->data());
        $this->assertCount(1, $transport->written());
    }

    public function testTransactTimesOutWhenOnlyLateRepliesArrive(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $transport = new FakeTransport([$this->reply(7, 'late')]);

        $this->expectException(TimeoutException::class);

        $this->connection($transport)->transact(new Request(6), retries: 0);
    }

    public function testTransactTriesAgainWhileTheReplyIsNotASuccess(): void
    {
        Sleep::fake();

        $transport = new FakeTransport([$this->echo(1), $this->echo(0)]);

        $this->assertTrue($this->connection($transport)->transact(new Request(6))->success());
        $this->assertCount(2, $transport->written());
    }

    public function testTransactReturnsTheLastReplyWhenNoAttemptSucceeds(): void
    {
        Sleep::fake();

        $failures = [];
        $this->app->make('events')->listen(TransactionFailed::class, function (TransactionFailed $event) use (&$failures): void {
            $failures[] = $event;
        });

        $transport = new FakeTransport([$this->echo(-4), $this->echo(-4), $this->echo(-4)]);
        $result = $this->connection($transport)->transact(new Request(6));

        $this->assertTrue($result->error());
        $this->assertSame(-4, $result->status());
        $this->assertCount(3, $transport->written());
        $this->assertCount(1, $failures);
        $this->assertSame('device', $failures[0]->connection);
        $this->assertSame(3, $failures[0]->attempts);
        $this->assertSame($result, $failures[0]->outcome);
        $this->assertSame(0, $failures[0]->request->sequence);
    }

    public function testTransactThrowsWhenTheDeviceNeverReplies(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $failures = [];
        $this->app->make('events')->listen(TransactionFailed::class, function (TransactionFailed $event) use (&$failures): void {
            $failures[] = $event;
        });

        $transport = new FakeTransport;

        try {
            $this->connection($transport)->transact(new Request(6));
            $this->fail('The transaction did not time out.');
        } catch (TimeoutException $exception) {
            $this->assertSame('The device on the [device] serial connection did not reply within 1 seconds.', $exception->getMessage());
        }

        $this->assertCount(3, $transport->written());
        $this->assertSame($exception, $failures[0]->outcome);
    }

    public function testTimeoutAndRetriesCanBeGivenForOneTransaction(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $transport = new FakeTransport;

        try {
            $this->connection($transport)->transact(new Request(6), timeout: 0.5, retries: 0);
            $this->fail('The transaction did not time out.');
        } catch (TimeoutException $exception) {
            $this->assertStringContainsString('within 0.5 seconds', $exception->getMessage());
        }

        $this->assertCount(1, $transport->written());
    }

    public function testATransportFailureClosesTheDeviceAndTheNextAttemptOpensItAgain(): void
    {
        Sleep::fake();

        $transport = new class([fn (string $written): string => (new HeaderProtocol)->reply(1, 0, 6)]) extends FakeTransport {
            public int $opened = 0;

            public bool $failed = false;

            public function open(): void
            {
                ++$this->opened;

                parent::open();
            }

            public function write(string $bytes): void
            {
                if (! $this->failed) {
                    $this->failed = true;

                    throw new TransportException('The device went away.');
                }

                parent::write($bytes);
            }
        };

        $this->assertTrue($this->connection($transport)->transact(new Request(6))->success());
        $this->assertSame(2, $transport->opened);
    }

    public function testExceptionsOtherThanSerialOnesAreNotRetried(): void
    {
        $exception = new RuntimeException('Not a serial failure.');
        $transport = new FakeTransport([function () use ($exception): string {
            throw $exception;
        }]);

        try {
            $this->connection($transport)->transact(new Request(6));
            $this->fail('The exception did not reach the caller.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertCount(1, $transport->written());
    }

    public function testTransactDispatchesTheFramesSentAndReceived(): void
    {
        $sent = $received = [];
        $events = $this->app->make('events');
        $events->listen(FrameSent::class, function (FrameSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $events->listen(FrameReceived::class, function (FrameReceived $event) use (&$received): void {
            $received[] = $event;
        });

        $transport = new FakeTransport([$reply = $this->reply(0, 'abc')]);
        $this->connection($transport)->transact(new Request(6));

        $this->assertSame('device', $sent[0]->connection);
        $this->assertSame($transport->written()[0], $sent[0]->bytes);
        $this->assertSame(0, $sent[0]->request->sequence);
        $this->assertSame($reply, $received[0]->frame->raw);
        $this->assertSame($sent[0]->request, $received[0]->request);
    }

    public function testTransactLogsFramesRetriesAndFailures(): void
    {
        Sleep::fake();

        $logger = m::spy(LoggerInterface::class);
        $transport = new FakeTransport([$this->echo(1), $this->echo(1)]);

        $this->connection($transport, ['logger' => $logger, 'retryPolicy' => new RetryPolicy(1, 0.1)])->transact(new Request(6));

        $logger->shouldHaveReceived('debug')->with('Serial frame sent.', ['connection' => 'device', 'bytes' => bin2hex($transport->written()[0])])->twice();
        $logger->shouldHaveReceived('debug')->with('Serial frame received.', m::type('array'))->twice();
        $logger->shouldHaveReceived('warning')->with('Serial transaction attempt failed; trying again.', [
            'connection' => 'device',
            'code' => 6,
            'sub_code' => null,
            'sequence' => 0,
            'attempt' => 1,
            'reason' => 'The device did not report success.',
        ])->once();
        $logger->shouldHaveReceived('error')->with('Serial transaction failed.', m::on(fn (array $context): bool => $context['attempt'] === 2))->once();
    }

    public function testTheLogNamesTheReasonAnAttemptFailed(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $logger = m::spy(LoggerInterface::class);

        try {
            $this->connection(new FakeTransport, ['logger' => $logger])->transact(new Request(6), retries: 0);
            $this->fail('The transaction did not time out.');
        } catch (TimeoutException) {
        }

        $logger->shouldHaveReceived('error')->with('Serial transaction failed.', m::on(
            fn (array $context): bool => $context['reason'] === 'The device on the [device] serial connection did not reply within 1 seconds.',
        ))->once();
    }

    public function testExclusiveRunsTheCallbackAndLetsItsTransactionsThrough(): void
    {
        $transport = new FakeTransport([$this->echo(), $this->echo()]);
        $connection = $this->connection($transport);

        $result = $connection->exclusive(function (Connection $held) use ($connection): string {
            $this->assertSame($connection, $held);

            $held->transact(new Request(6));
            $held->exclusive(fn () => $held->transact(new Request(6)));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertCount(2, $transport->written());
    }

    public function testExclusiveKeepsOtherCoroutinesWaitingUntilTheCallbackReturns(): void
    {
        $connection = $this->connection(new FakeTransport);
        $order = [];

        parallel([
            function () use ($connection, &$order): void {
                $connection->exclusive(function () use (&$order): void {
                    $order[] = 'first starts';
                    usleep(20_000);
                    $order[] = 'first ends';
                });
            },
            function () use ($connection, &$order): void {
                usleep(2_000);
                $connection->exclusive(function () use (&$order): void {
                    $order[] = 'second';
                });
            },
        ]);

        $this->assertSame(['first starts', 'first ends', 'second'], $order);
    }

    public function testACoroutineCanceledWhileWaitingForTheDeviceLeavesItFree(): void
    {
        $connection = $this->connection(new FakeTransport);
        $group = new WaitGroup(2);
        $caught = null;

        try {
            Coroutine::create(function () use ($connection, $group): void {
                try {
                    $connection->exclusive(fn () => usleep(30_000));
                } finally {
                    $group->done();
                }
            });

            $waiting = Coroutine::create(function () use ($connection, $group, &$caught): void {
                try {
                    $connection->exclusive(fn () => null);
                } catch (CanceledException $exception) {
                    $caught = $exception;
                } finally {
                    $group->done();
                }
            });

            usleep(5_000);
            SwooleCoroutine::cancel($waiting);
        } finally {
            $group->wait(1.0);
        }

        $this->assertInstanceOf(CanceledException::class, $caught);
        $this->assertSame('Waiting for the [device] serial connection was canceled.', $caught->getMessage());
        $this->assertSame('free', $connection->exclusive(fn (): string => 'free'));
    }

    public function testExclusiveHoldsTheCrossProcessLockWhileTheCallbackRuns(): void
    {
        $store = $this->app->make('cache')->store('array')->getStore();
        $connection = $this->connection(new FakeTransport, ['lock' => fn () => $store->lock('serial:device', 10)]);

        $connection->exclusive(function () use ($store): void {
            $this->assertTrue($store->lock('serial:device')->isLocked());
        });

        $this->assertFalse($store->lock('serial:device')->isLocked());
    }

    public function testExclusiveFailsWhenAnotherProcessHoldsTheLock(): void
    {
        $store = $this->app->make('cache')->store('array')->getStore();
        $other = $store->lock('serial:device', 10);
        $other->acquire();
        $connection = $this->connection(new FakeTransport, ['lock' => fn () => $store->lock('serial:device', 10), 'lockWait' => 0]);

        try {
            $connection->exclusive(fn () => $this->fail('The callback ran without the lock.'));
            $this->fail('The lock was not refused.');
        } catch (LockTimeoutException) {
        }

        $other->release();

        $this->assertSame('free', $connection->exclusive(fn (): string => 'free'));
    }

    public function testDisconnectClosesTheDevice(): void
    {
        $transport = new FakeTransport([$this->echo()]);
        $connection = $this->connection($transport);
        $connection->transact(new Request(6));

        $connection->disconnect();

        $this->assertFalse($transport->isOpen());
    }

    public function testGettersReturnWhatTheConnectionWasMadeWith(): void
    {
        $transport = new FakeTransport;
        $connection = $this->connection($transport);

        $this->assertSame('device', $connection->getName());
        $this->assertSame($transport, $connection->getTransport());
        $this->assertSame($this->protocol, $connection->getProtocol());
    }

    public function testAReservationRefusesTransactionsForAnyoneButItsOwner(): void
    {
        $transport = new FakeTransport([$this->echo()]);
        $connection = $this->connection($transport);

        $this->assertTrue($connection->reserve('calibration'));
        $this->assertSame('calibration', $connection->reservedBy());

        foreach ([null, 'slot-3'] as $owner) {
            try {
                $connection->transact(new Request(6), owner: $owner);
                $this->fail('The transaction went through a reservation.');
            } catch (ReservedException $exception) {
                $this->assertSame('The [device] serial connection is reserved for [calibration].', $exception->getMessage());
            }
        }

        $this->assertSame([], $transport->written());
        $this->assertTrue($connection->transact(new Request(6), owner: 'calibration')->success());
    }

    public function testOnlyTheOwnerReservesAgainOrReleases(): void
    {
        $connection = $this->connection(new FakeTransport);
        $connection->reserve('calibration');

        $this->assertFalse($connection->reserve('slot-3'));
        $this->assertTrue($connection->reserve('calibration'));
        $this->assertFalse($connection->release('slot-3'));
        $this->assertTrue($connection->release('calibration'));
        $this->assertNull($connection->reservedBy());
        $this->assertFalse($connection->release('calibration'));
        $this->assertTrue($connection->reserve('slot-3'));
    }

    public function testAReservationLapsesItsSecondsAfterTheOwnersLastTransaction(): void
    {
        $this->freezeTime();

        $connection = $this->connection(new FakeTransport([$this->echo()]));
        $connection->reserve('slot-3', 5.0);

        $this->travel(4)->seconds();
        $connection->transact(new Request(6), owner: 'slot-3');

        $this->travel(4)->seconds();
        $this->assertSame('slot-3', $connection->reservedBy());

        $this->travel(1)->seconds();
        $this->assertNull($connection->reservedBy());
        $this->assertFalse($connection->release('slot-3'));
        $this->assertTrue($connection->reserve('calibration'));
    }

    public function testATransactionAfterTheReservationLapsedDoesNotRenewIt(): void
    {
        $this->freezeTime();

        $transport = new FakeTransport([$this->echo(), $this->echo()]);
        $connection = $this->connection($transport);
        $connection->reserve('slot-3', 5.0);

        $this->travel(6)->seconds();
        $connection->transact(new Request(6), owner: 'slot-3');

        $this->assertNull($connection->reservedBy());
        $this->assertTrue($connection->transact(new Request(6))->success());
    }

    /**
     * Create a connection to the given fake device.
     *
     * @param array<string, mixed> $overrides
     */
    protected function connection(FakeTransport $transport, array $overrides = []): Connection
    {
        return new Connection(...[
            'name' => 'device',
            'transport' => $transport,
            'protocol' => $this->protocol,
            'sequence' => new Counter,
            'retryPolicy' => new RetryPolicy(2, 0.1),
            'timeout' => 1.0,
            'pollInterval' => 0.01,
            'events' => $this->app->make('events'),
            ...$overrides,
        ]);
    }

    /**
     * Build the device's reply to the request numbered with the given sequence.
     */
    protected function reply(int $sequence, string $payload = '', int $status = 0): string
    {
        return $this->protocol->reply($sequence + 1, $status, 6, $payload);
    }

    /**
     * Build a reply to whichever request is written, with the given status.
     */
    protected function echo(int $status = 0): Closure
    {
        return fn (string $written): string => $this->reply($this->sequenceOf($written), '', $status);
    }

    /**
     * Get the sequence number of a request as it was written.
     */
    protected function sequenceOf(string $written): int
    {
        return (int) hexdec(substr($written, 1, 4));
    }
}
