<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Log\LogManager;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;
use Ipsocode\Serial\Connection;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Facades\Serial;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\SerialManager;
use Ipsocode\Serial\SerialServiceProvider;
use Ipsocode\Serial\Testing\FakeTransport;
use Ipsocode\Serial\Testing\SimulatedProtocol;
use Ipsocode\Serial\Tests\Fixtures\CountingSimulator;
use Ipsocode\Serial\Tests\Fixtures\HeaderProtocol;
use Ipsocode\Serial\Tests\Fixtures\NoLockStore;
use Ipsocode\Serial\Tests\TestCase;
use Ipsocode\Serial\Transports\DioTransport;
use Ipsocode\Serial\Transports\MemoryTransport;
use Ipsocode\Serial\Transports\StreamTransport;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ValueError;

class SerialManagerTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get('config');

        $config->set('serial.connections.loopback', [
            'transport' => 'memory',
            'protocol' => HeaderProtocol::class,
            'timeout' => 1.0,
            'retries' => 0,
            'retry_delay' => 0.1,
            'poll_interval' => 0.01,
        ]);

        $config->set('serial.connections.simulated', [
            'protocol' => HeaderProtocol::class,
            'simulator' => CountingSimulator::class,
            'timeout' => 1.0,
            'retries' => 2,
            'retry_delay' => 0.0,
            'poll_interval' => 0.01,
        ]);
    }

    public function testTheProviderRegistersTheManagerAndMergesTheDefaultConfig(): void
    {
        $this->assertInstanceOf(SerialManager::class, $this->app->make('serial'));
        $this->assertSame($this->app->make('serial'), $this->app->make(SerialManager::class));
        $this->assertSame($this->app->make('serial'), Serial::getFacadeRoot());

        $config = $this->app->get('config');

        $this->assertSame('default', $config->get('serial.default'));
        $this->assertSame(['default', 'loopback', 'simulated'], array_keys($config->get('serial.connections')));
        $this->assertSame('stream', $config->get('serial.connections.default.transport'));
    }

    public function testTheConfigIsPublishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(SerialServiceProvider::class, 'serial-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/serial.php', array_values($paths)[0]);
    }

    public function testConnectionReturnsTheSameConnectionEachTime(): void
    {
        $manager = $this->app->make(SerialManager::class);
        $connection = $manager->connection('loopback');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('loopback', $connection->getName());
        $this->assertSame($connection, $manager->connection('loopback'));
        $this->assertInstanceOf(MemoryTransport::class, $connection->getTransport());
        $this->assertInstanceOf(HeaderProtocol::class, $connection->getProtocol());
    }

    public function testTheDefaultConnectionIsTheConfiguredOne(): void
    {
        $manager = $this->app->make(SerialManager::class);

        $this->assertSame('default', $manager->getDefaultInstance());
        $this->assertInstanceOf(StreamTransport::class, $manager->connection()->getTransport());

        $manager->setDefaultInstance('loopback');

        $this->assertSame('loopback', $manager->connection()->getName());
    }

    public function testAnUnconfiguredConnectionFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serial connection [missing] is not configured.');

        Serial::connection('missing');
    }

    public function testAConnectionWithoutATransportFails(): void
    {
        $this->app->get('config')->set('serial.connections.broken', ['protocol' => HeaderProtocol::class]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Instance [broken] does not specify a transport.');

        Serial::connection('broken');
    }

    public function testTheDioTransportTakesTheLineSettings(): void
    {
        $this->app->get('config')->set('serial.connections.default.transport', 'dio');
        $this->app->get('config')->set('serial.connections.default.parity', 'even');

        $this->assertInstanceOf(DioTransport::class, Serial::connection()->getTransport());
    }

    public function testTheDioTransportRejectsAnUnknownParity(): void
    {
        $this->app->get('config')->set('serial.connections.default.transport', 'dio');
        $this->app->get('config')->set('serial.connections.default.parity', 'mark');

        $this->expectException(ValueError::class);

        Serial::connection();
    }

    public function testExtendAddsATransport(): void
    {
        $transport = new FakeTransport;
        $device = null;
        $this->app->get('config')->set('serial.connections.default.transport', 'fake');

        // The manager binds the callback to itself, so it records what it is given instead of asserting.
        Serial::extend('fake', function ($app, array $config) use ($transport, &$device): Transport {
            $device = $config['device'];

            return $transport;
        });

        $this->assertSame($transport, Serial::connection()->getTransport());
        $this->assertSame('/dev/ttyUSB0', $device);
    }

    public function testASimulatedConnectionAnswersFromItsSimulator(): void
    {
        $connection = Serial::connection('simulated');

        $this->assertInstanceOf(MemoryTransport::class, $connection->getTransport());
        $this->assertInstanceOf(SimulatedProtocol::class, $connection->getProtocol());
        $this->assertSame('ping', $connection->transact(new Request(6, 'ping'))->data());
    }

    public function testEachSimulatedConnectionHasItsOwnSimulatorAndSequence(): void
    {
        $this->app->get('config')->set('serial.connections.other', $this->app->get('config')->get('serial.connections.simulated'));

        Serial::connection('simulated')->transact(new Request(6));
        $result = Serial::connection('other')->transact(new Request(6));

        $this->assertTrue($result->success());
    }

    public function testTheLogSettingNamesTheChannelThatRecordsFrames(): void
    {
        $logger = m::spy(LoggerInterface::class);
        $log = m::mock(LogManager::class);
        $log->shouldReceive('channel')->with('serial')->once()->andReturn($logger);
        $this->app->instance('log', $log);
        $this->app->get('config')->set('serial.connections.simulated.log', 'serial');

        Serial::connection('simulated')->transact(new Request(6));

        $logger->shouldHaveReceived('debug')->with('Serial frame sent.', m::type('array'))->once();
    }

    public function testTheLockSettingTakesACrossProcessLockFromTheCache(): void
    {
        $this->app->get('config')->set('serial.connections.simulated.lock', ['store' => 'array', 'seconds' => 10, 'wait' => 0]);
        $store = $this->app->make('cache')->store('array')->getStore();
        $locked = null;

        Serial::connection('simulated')->exclusive(function () use ($store, &$locked): void {
            $locked = $store->lock('serial:simulated')->isLocked();
        });

        $this->assertTrue($locked);
    }

    public function testTheLockSettingKeepsTheSequenceInTheLocksStore(): void
    {
        $this->app->get('config')->set('serial.connections.simulated.lock', ['store' => 'array', 'seconds' => 10, 'wait' => 0]);

        Serial::connection('simulated')->transact(new Request(6));

        $this->assertSame(1, $this->app->make('cache')->store('array')->get('serial:simulated:sequence'));
    }

    public function testTheLockSettingNeedsAStoreThatHoldsLocks(): void
    {
        $this->app->make('cache')->extend('no-locks', fn () => $this->app->make('cache')->repository(new NoLockStore));
        $this->app->get('config')->set('cache.stores.no-locks', ['driver' => 'no-locks']);
        $this->app->get('config')->set('serial.connections.simulated.lock', ['store' => 'no-locks', 'seconds' => 10, 'wait' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The cache store of the [simulated] serial connection's lock cannot hold locks.");

        Serial::connection('simulated');
    }

    public function testDisconnectClosesAResolvedConnectionsDevice(): void
    {
        $connection = Serial::connection('loopback');
        $connection->getTransport()->open();

        Serial::disconnect('loopback');
        Serial::disconnect('simulated');

        $this->assertFalse($connection->getTransport()->isOpen());
    }

    public function testPurgeClosesTheDeviceAndForgetsTheConnection(): void
    {
        $this->app->get('config')->set('serial.default', 'loopback');
        $connection = Serial::connection();
        $connection->getTransport()->open();

        Serial::purge();

        $this->assertFalse($connection->getTransport()->isOpen());
        $this->assertNotSame($connection, Serial::connection());
    }

    public function testTheManagerSendsOnTheDefaultConnection(): void
    {
        $this->app->get('config')->set('serial.default', 'simulated');

        $this->assertSame('hello', Serial::transact(new Request(6, 'hello'))->data());
    }
}
