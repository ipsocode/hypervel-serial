<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Log\LogManager;
use Hypervel\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Ipsocode\Serial\Contracts\Protocol;
use Ipsocode\Serial\Contracts\Sequence;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Sequences\Counter;
use Ipsocode\Serial\Sequences\SharedCounter;
use Ipsocode\Serial\Testing\SimulatedProtocol;
use Ipsocode\Serial\Testing\Simulator;
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
        /** @var Connection $connection */
        $connection = $this->instance($name);

        return $connection;
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
     * Get the configuration of the given connection, with its name under the "name" key.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the connection is not configured
     */
    public function getInstanceConfig(string $name): array
    {
        /** @var null|array<string, mixed> $config */
        $config = $this->config->get("serial.connections.{$name}");

        $config ??= throw new InvalidArgumentException("Serial connection [{$name}] is not configured.");

        return [...$config, 'name' => $name];
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
        $protocol = $this->protocol($name);

        if (isset($config['simulator'])) {
            /** @var Simulator $simulator */
            $simulator = $this->app->makeTransient($this->config->string($this->key($name, 'simulator')));

            return $this->createConnection($name, $config, new MemoryTransport, new SimulatedProtocol($protocol, $simulator->respond(...)));
        }

        /** @var Transport $transport */
        $transport = parent::resolve($name);

        return $this->createConnection($name, $config, $transport, $protocol);
    }

    /**
     * Resolve the protocol of the given connection.
     */
    protected function protocol(string $name): Protocol
    {
        /** @var Protocol $protocol */
        $protocol = $this->app->make($this->config->string($this->key($name, 'protocol')));

        return $protocol;
    }

    /**
     * Create a connection to the device behind the given transport.
     *
     * @param array<string, mixed> $config
     */
    protected function createConnection(string $name, array $config, Transport $transport, Protocol $protocol): Connection
    {
        /** @var null|array{store?: null|string, seconds: int, wait: int} $lock */
        $lock = $config['lock'] ?? null;
        $store = null;
        $factory = null;

        if ($lock !== null) {
            /** @var CacheManager $cache */
            $cache = $this->app->make('cache');
            /** @var Repository $store */
            $store = $cache->store($lock['store'] ?? null);
            $factory = $this->createLock($name, $this->lockProvider($name, $store), $lock['seconds']);
        }

        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        return new Connection(
            name: $name,
            transport: $transport,
            protocol: $protocol,
            sequence: $this->createSequence($name, $config, $store),
            retryPolicy: new RetryPolicy($this->config->integer($this->key($name, 'retries')), $this->seconds($name, 'retry_delay')),
            timeout: $this->seconds($name, 'timeout'),
            pollInterval: $this->seconds($name, 'poll_interval'),
            events: $events,
            logger: isset($config['log']) ? $this->app->make(LogManager::class)->channel($this->config->string($this->key($name, 'log'))) : null,
            lock: $factory,
            lockWait: $lock === null ? 0 : $lock['wait'],
            lockSeconds: $lock === null ? 0 : $lock['seconds'],
        );
    }

    /**
     * Get the config key of a setting of the given connection.
     */
    protected function key(string $name, string $setting): string
    {
        return "serial.connections.{$name}.{$setting}";
    }

    /**
     * Get a setting in seconds, which may be an integer or a float.
     *
     * @throws InvalidArgumentException when the setting is neither
     */
    protected function seconds(string $name, string $setting): float
    {
        $key = $this->key($name, $setting);
        $value = $this->config->get($key);

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be a float, %s given.', $key, gettype($value)));
        }

        return (float) $value;
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
        /** @var class-string<Sequence> $class */
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
     * @return Closure(): RefreshableLock
     *
     * @throws InvalidArgumentException when the cache store's locks cannot be refreshed
     */
    protected function createLock(string $name, LockProvider $provider, int $seconds): Closure
    {
        $key = "serial:{$name}";

        // A hold that outlasts the lock is kept alive by refreshing it, which not every driver can do.
        if (! $provider->lock($key, $seconds) instanceof RefreshableLock) {
            throw new InvalidArgumentException("The cache store of the [{$name}] serial connection's lock cannot refresh its locks.");
        }

        // A new lock for each hold gives each hold its own owner, even in workers forked from
        // a process that resolved the connection.
        return function () use ($provider, $key, $seconds): RefreshableLock {
            /** @var RefreshableLock $lock */
            $lock = $provider->lock($key, $seconds);

            return $lock;
        };
    }

    /**
     * Create a transport that reads and writes a device opened as a stream.
     *
     * @param array<string, mixed> $config
     */
    protected function createStreamTransport(array $config): StreamTransport
    {
        /** @var string $name */
        $name = $config['name'];

        return new StreamTransport(
            $this->config->string($this->key($name, 'device')),
            $this->seconds($name, 'timeout'),
            $this->seconds($name, 'poll_interval'),
        );
    }

    /**
     * Create a transport that reads and writes a device opened through ext-dio.
     *
     * @param array<string, mixed> $config
     */
    protected function createDioTransport(array $config): DioTransport
    {
        /** @var string $name */
        $name = $config['name'];

        return new DioTransport(
            device: $this->config->string($this->key($name, 'device')),
            baud: $this->config->integer($this->key($name, 'baud')),
            dataBits: $this->config->integer($this->key($name, 'data_bits')),
            stopBits: $this->config->integer($this->key($name, 'stop_bits')),
            parity: Parity::from($this->config->string($this->key($name, 'parity'))),
            flowControl: $this->config->boolean($this->key($name, 'flow_control')),
            timeout: $this->seconds($name, 'timeout'),
            pollInterval: $this->seconds($name, 'poll_interval'),
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
