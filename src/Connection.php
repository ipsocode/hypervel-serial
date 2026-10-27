<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Closure;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Contracts\Protocol;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Contracts\Sequence;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Events\FrameReceived;
use Ipsocode\Serial\Events\FrameSent;
use Ipsocode\Serial\Events\TransactionFailed;
use Ipsocode\Serial\Exceptions\ReservedException;
use Ipsocode\Serial\Exceptions\SerialException;
use Ipsocode\Serial\Exceptions\TimeoutException;
use Ipsocode\Serial\Exceptions\TransportException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\CanceledException;

/**
 * One device, shared by every coroutine in the worker. A transaction holds the device from
 * the request until its reply, so transactions from different coroutines never interleave.
 */
class Connection
{
    /**
     * The one-slot channel the coroutine holding the device has filled.
     *
     * @var Channel<true>
     */
    protected Channel $mutex;

    /**
     * The coroutine holding the device, if any.
     */
    protected ?int $holder = null;

    /**
     * The owner the device is reserved for, if any.
     *
     * The reservation belongs to the device, so every coroutine in the worker sees it.
     */
    protected ?string $reservedBy = null;

    /**
     * The seconds the reservation lasts after its owner's last transaction, or null until it is released.
     */
    protected ?float $reservedFor = null;

    /**
     * When the reservation lapses, unless its owner sends a transaction first.
     */
    protected ?CarbonImmutable $reservedUntil = null;

    /**
     * Create a new connection.
     *
     * @param float $timeout the seconds to wait for a reply
     * @param float $pollInterval the seconds to wait between reads while a reply arrives
     * @param null|Closure(): Lock $lock the cross-process lock each hold of the device takes
     * @param int $lockWait the seconds to wait for the cross-process lock
     */
    public function __construct(
        protected string $name,
        protected Transport $transport,
        protected Protocol $protocol,
        protected Sequence $sequence,
        protected RetryPolicy $retryPolicy,
        protected float $timeout,
        protected float $pollInterval,
        protected Dispatcher $events,
        protected ?LoggerInterface $logger = null,
        protected ?Closure $lock = null,
        protected int $lockWait = 0,
    ) {
        $this->mutex = new Channel(1);
    }

    /**
     * Get the connection's name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the connection's transport.
     */
    public function getTransport(): Transport
    {
        return $this->transport;
    }

    /**
     * Get the connection's protocol.
     */
    public function getProtocol(): Protocol
    {
        return $this->protocol;
    }

    /**
     * Send the request and return the device's reply, trying again while the reply is not a success.
     *
     * @param null|float $timeout the seconds to wait for each reply, in place of the connection's
     * @param null|int $retries the attempts allowed after the first, in place of the connection's
     * @param null|string $owner the owner the request is sent for, which may use the device while it holds a reservation
     *
     * @throws ReservedException when the device is reserved for another owner
     * @throws SerialException when the last attempt gets no valid reply
     */
    public function transact(Request $request, ?float $timeout = null, ?int $retries = null, ?string $owner = null): Result
    {
        return $this->exclusive(function () use ($request, $timeout, $retries, $owner): Result {
            if ($this->reservedForAnother($owner)) {
                throw new ReservedException("The [{$this->name}] serial connection is reserved for [{$this->reservedBy}].");
            }

            $renews = $owner !== null && $this->reservedBy() === $owner;

            try {
                return $this->attempt($request, $timeout, $retries);
            } finally {
                if ($renews) {
                    $this->renewReservation();
                }
            }
        });
    }

    /**
     * Reserve the device for the owner, so only transactions sent for it go through until it releases the device.
     *
     * The owner names whoever holds the reservation, such as a class, and renews a reservation it
     * already holds by reserving again. Transactions sent for no owner, or another, are refused.
     *
     * @param null|float $seconds the seconds the reservation lasts after the owner's last transaction, or null until it is released
     * @return bool false when the device is reserved for another owner
     */
    public function reserve(string $owner, ?float $seconds = null): bool
    {
        return $this->exclusive(function () use ($owner, $seconds): bool {
            if ($this->reservedForAnother($owner)) {
                return false;
            }

            $this->reservedBy = $owner;
            $this->reservedFor = $seconds;

            $this->renewReservation();

            return true;
        });
    }

    /**
     * Release the owner's reservation of the device.
     *
     * @return bool false when the device was not reserved for the owner
     */
    public function release(string $owner): bool
    {
        return $this->exclusive(function () use ($owner): bool {
            if ($this->reservedBy() !== $owner) {
                return false;
            }

            $this->reservedBy = $this->reservedFor = $this->reservedUntil = null;

            return true;
        });
    }

    /**
     * Get the owner the device is reserved for, or null when it is not reserved.
     */
    public function reservedBy(): ?string
    {
        if ($this->reservedUntil !== null && CarbonImmutable::now()->greaterThanOrEqualTo($this->reservedUntil)) {
            return null;
        }

        return $this->reservedBy;
    }

    /**
     * Send the request, trying again while the reply is not a success.
     *
     * @throws SerialException when the last attempt gets no valid reply
     */
    protected function attempt(Request $request, ?float $timeout, ?int $retries): Result
    {
        $request = $request->withSequence($this->sequence->next($request));
        $timeout ??= $this->timeout;
        $retryPolicy = $retries === null ? $this->retryPolicy : $this->retryPolicy->withTimes($retries);

        for ($attempt = 1;; ++$attempt) {
            try {
                $outcome = $this->exchange($request, $timeout);
            } catch (SerialException $exception) {
                $outcome = $exception;
            }

            if (! $retryPolicy->shouldRetry($attempt, $outcome)) {
                break;
            }

            $this->logger?->warning('Serial transaction attempt failed; trying again.', $this->context($request, $attempt, $outcome));

            Sleep::sleep($retryPolicy->delay);
        }

        if ($outcome instanceof SerialException || ! $outcome->success()) {
            $this->failed($request, $attempt, $outcome);
        }

        if ($outcome instanceof SerialException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Determine if the device is reserved for an owner other than the given one.
     */
    protected function reservedForAnother(?string $owner): bool
    {
        $reservedBy = $this->reservedBy();

        return $reservedBy !== null && $reservedBy !== $owner;
    }

    /**
     * Start the reservation's time again from now.
     */
    protected function renewReservation(): void
    {
        $this->reservedUntil = $this->reservedFor === null
            ? null
            : CarbonImmutable::now()->addMicroseconds((int) ($this->reservedFor * 1_000_000));
    }

    /**
     * Run the callback while holding the device, so no other coroutine's transaction comes between its own.
     *
     * Transactions the callback runs in its own coroutine go straight through. A coroutine the
     * callback starts does not share the hold, and its transactions wait until the callback returns.
     *
     * @template TReturn
     *
     * @param Closure(static): TReturn $callback
     * @return TReturn
     *
     * @throws CanceledException when the coroutine is canceled while waiting for the device
     * @throws LockTimeoutException when another process holds the cross-process lock for too long
     */
    public function exclusive(Closure $callback): mixed
    {
        $coroutine = Coroutine::id();

        if ($this->holder === $coroutine) {
            return $callback($this);
        }

        if (! $this->mutex->push(true)) {
            throw new CanceledException("Waiting for the [{$this->name}] serial connection was canceled.");
        }

        $this->holder = $coroutine;

        try {
            return $this->lock === null
                ? $callback($this)
                : ($this->lock)()->block($this->lockWait, fn () => $callback($this));
        } finally {
            $this->holder = null;

            $this->mutex->pop();
        }
    }

    /**
     * Close the device once no transaction is using it. The next transaction opens it again.
     */
    public function disconnect(): void
    {
        $this->exclusive(fn () => $this->transport->close());
    }

    /**
     * Send the request once and wait for the device's reply.
     *
     * @throws SerialException
     */
    protected function exchange(Request $request, float $timeout): Result
    {
        $bytes = $this->protocol->encode($request);

        try {
            if (! $this->transport->isOpen()) {
                $this->transport->open();
            }

            $this->transport->flush();
            $this->transport->write($bytes);

            $this->sent($request, $bytes);

            $frame = $this->receive($timeout);
        } catch (TransportException $exception) {
            // The next attempt opens the device again, which recovers one that went away and came back.
            $this->transport->close();

            throw $exception;
        }

        $this->received($request, $frame);

        return $this->protocol->validate($frame, $request);
    }

    /**
     * Read from the device until the protocol finds a complete frame.
     *
     * @throws SerialException
     */
    protected function receive(float $timeout): Frame
    {
        $buffer = new Buffer;
        $deadline = CarbonImmutable::now()->addMicroseconds((int) ($timeout * 1_000_000));

        while (true) {
            $buffer->append($this->transport->read());

            if (($frame = $this->protocol->decode($buffer)) !== null) {
                return $frame;
            }

            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw new TimeoutException("The device on the [{$this->name}] serial connection did not reply within {$timeout} seconds.");
            }

            Sleep::sleep($this->pollInterval);
        }
    }

    /**
     * Record the bytes sent to the device.
     */
    protected function sent(Request $request, string $bytes): void
    {
        $this->logger?->debug('Serial frame sent.', ['connection' => $this->name, 'bytes' => bin2hex($bytes)]);

        if ($this->events->hasListeners(FrameSent::class)) {
            $this->events->dispatch(new FrameSent($this->name, $request, $bytes));
        }
    }

    /**
     * Record the frame the device replied with.
     */
    protected function received(Request $request, Frame $frame): void
    {
        $this->logger?->debug('Serial frame received.', ['connection' => $this->name, 'bytes' => bin2hex($frame->raw)]);

        if ($this->events->hasListeners(FrameReceived::class)) {
            $this->events->dispatch(new FrameReceived($this->name, $request, $frame));
        }
    }

    /**
     * Record a transaction whose last attempt did not succeed.
     */
    protected function failed(Request $request, int $attempts, Result|SerialException $outcome): void
    {
        $this->logger?->error('Serial transaction failed.', $this->context($request, $attempts, $outcome));

        if ($this->events->hasListeners(TransactionFailed::class)) {
            $this->events->dispatch(new TransactionFailed($this->name, $request, $attempts, $outcome));
        }
    }

    /**
     * Get the log context of an attempt.
     *
     * @return array<string, mixed>
     */
    protected function context(Request $request, int $attempt, Result|SerialException $outcome): array
    {
        return [
            'connection' => $this->name,
            'code' => $request->code,
            'sub_code' => $request->subCode,
            'sequence' => $request->sequence,
            'attempt' => $attempt,
            'reason' => $outcome instanceof SerialException ? $outcome->getMessage() : 'The device did not report success.',
        ];
    }
}
