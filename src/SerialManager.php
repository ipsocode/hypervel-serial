<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Closure;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Ipsocode\Serial\Contracts\Protocol;
use Ipsocode\Serial\Contracts\Sequence;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Sequences\Counter;
use Ipsocode\Serial\Sequences\SharedCounter;
use Ipsocode\Serial\Testing\SimulatedProtocol;
use Ipsocode\Serial\Transports\DioTransport;
use Ipsocode\Serial\Transports\MemoryTransport;
use Ipsocode\Serial\Transports\StreamTransport;

/**
 * @mixin Connection
 */
class SerialManager extends MultipleInstanceManager
{
    /**
     * The key name of the "driver" equivalent configuration option.
     */
    protected string $driverKey = 'transport';

    /**
     * Get a serial connection by name.
     */
    public function connection(?string $name = null): Connection
    {
        return $this->instance($name);
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultInstance(): string
    {
        return $this->config->string('serial.default');
    }

    /**
     * Set the default connection name.
     *
     * Boot-only. It changes the process-global configuration, so every coroutine in the
     * worker sends on the new default from then on.
     */
    public function setDefaultInstance(string $name): void
    {
        $this->config->set('serial.default', $name);
    }

    /**
     * Get the configuration of the given connection.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the connection is not configured
     */
    public function getInstanceConfig(string $name): array
    {
        return $this->config->get("serial.connections.{$name}")
            ?? throw new InvalidArgumentException("Serial connection [{$name}] is not configured.");
    }

    /**
     * Close the given connection's device. Its next transaction opens it again.
     */
    public function disconnect(?string $name = null): void
    {
        $name ??= $this->getDefaultInstance();

        if (isset($this->instances[$name])) {
            $this->instances[$name]->disconnect();
        }
    }

    /**
     * Close the given connection's device and forget the connection.
     *
     * Boot or tests only. A coroutine that already holds the connection keeps using it, while
     * the next one to ask for it gets a new connection to the same device.
     */
    public function purge(?string $name = null): void
    {
        $this->disconnect($name);

        parent::purge($name);
    }

    /**
     * Resolve the given connection.
     *
     * @throws InvalidArgumentException when the connection is not configured
     */
    protected function resolve(string $name): Connection
    {
        $config = $this->getInstanceConfig($name);
        $protocol = $this->app->make($config['protocol']);

        if (isset($config['simulator'])) {
            $simulator = $this->app->makeTransient($config['simulator']);

            return $this->createConnection($name, $config, new MemoryTransport, new SimulatedProtocol($protocol, $simulator->respond(...)));
        }

        return $this->createConnection($name, $config, parent::resolve($name), $protocol);
    }

    /**
     * Create a connection to the device behind the given transport.
     *
     * @param array<string, mixed> $config
     */
    protected function createConnection(string $name, array $config, Transport $transport, Protocol $protocol): Connection
    {
        $lock = $config['lock'] ?? null;
        $store = $lock === null ? null : $this->app->make('cache')->store($lock['store'] ?? null);

        return new Connection(
            name: $name,
            transport: $transport,
            protocol: $protocol,
            sequence: $this->createSequence($name, $config, $store),
            retryPolicy: new RetryPolicy($config['retries'], $config['retry_delay']),
            timeout: $config['timeout'],
            pollInterval: $config['poll_interval'],
            events: $this->app->make('events'),
            logger: isset($config['log']) ? $this->app->make('log')->channel($config['log']) : null,
            lock: $store === null ? null : $this->createLock($name, $this->lockProvider($name, $store), $lock['seconds']),
            lockWait: $lock === null ? 0 : $lock['wait'],
        );
    }

    /**
     * Create the sequence that numbers a connection's requests.
     *
     * The default counter keeps its number in the lock's cache store, so processes sharing the
     * device continue one sequence and a late reply to one is never taken for another's.
     *
     * @param array<string, mixed> $config
     */
    protected function createSequence(string $name, array $config, ?Repository $store): Sequence
    {
        $class = $config['sequence'] ?? Counter::class;

        if ($store !== null && $class === Counter::class) {
            return new SharedCounter($store, "serial:{$name}:sequence");
        }

        return $this->app->makeTransient($class);
    }

    /**
     * Get the lock provider behind the cache store of a connection's cross-process lock.
     *
     * @throws InvalidArgumentException when the cache store cannot hold locks
     */
    protected function lockProvider(string $name, Repository $store): LockProvider
    {
        $provider = $store->getStore();

        if (! $provider instanceof LockProvider) {
            throw new InvalidArgumentException("The cache store of the [{$name}] serial connection's lock cannot hold locks.");
        }

        return $provider;
    }

    /**
     * Create the factory of the cross-process lock a connection's hold of its device takes.
     *
     * @return Closure(): Lock
     */
    protected function createLock(string $name, LockProvider $provider, int $seconds): Closure
    {
        // A new lock for each hold gives each hold its own owner, even in workers forked from
        // a process that resolved the connection.
        return fn (): Lock => $provider->lock("serial:{$name}", $seconds);
    }

    /**
     * Create a transport that reads and writes a device opened as a stream.
     *
     * @param array<string, mixed> $config
     */
    protected function createStreamTransport(array $config): StreamTransport
    {
        return new StreamTransport($config['device'], $config['timeout'], $config['poll_interval']);
    }

    /**
     * Create a transport that reads and writes a device opened through ext-dio.
     *
     * @param array<string, mixed> $config
     */
    protected function createDioTransport(array $config): DioTransport
    {
        return new DioTransport(
            device: $config['device'],
            baud: $config['baud'],
            dataBits: $config['data_bits'],
            stopBits: $config['stop_bits'],
            parity: Parity::from($config['parity']),
            flowControl: $config['flow_control'],
            timeout: $config['timeout'],
            pollInterval: $config['poll_interval'],
        );
    }

    /**
     * Create a loopback transport.
     *
     * @param array<string, mixed> $config
     */
    protected function createMemoryTransport(array $config): MemoryTransport
    {
        return new MemoryTransport;
    }
}
