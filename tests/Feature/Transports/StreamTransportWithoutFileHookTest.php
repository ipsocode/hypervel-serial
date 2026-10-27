<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature\Transports;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Sleep;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Exceptions\TransportException;
use Ipsocode\Serial\Transports\StreamTransport;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

/**
 * Runs its own coroutines with Swoole's file hook off, as an application that sets its own hook
 * flags does. A write there can take only some of the bytes, and none once the device is full.
 */
class StreamTransportWithoutFileHookTest extends UnitTestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $directory;

    protected string $fifo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('serial-stream-unhooked');
        (new Filesystem)->deleteDirectory($this->directory);
        mkdir($this->directory, 0700, true);

        $this->fifo = $this->directory . '/tty';
        posix_mkfifo($this->fifo, 0600);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testAWriteWaitsWhileTheDeviceTakesTheBytes(): void
    {
        $bytes = random_bytes(200_000);
        $received = '';

        $this->runWithoutFileHook(function () use ($bytes, &$received): void {
            $transport = new StreamTransport($this->fifo, 5.0, 0.001);
            $transport->open();
            $reader = fopen($this->fifo, 'rbn');

            try {
                parallel([
                    fn () => $transport->write($bytes),
                    function () use ($reader, $bytes, &$received): void {
                        while (strlen($received) < strlen($bytes)) {
                            $received .= fread($reader, 65536);
                            usleep(1_000);
                        }
                    },
                ]);
            } finally {
                fclose($reader);
                $transport->close();
            }
        });

        $this->assertSame($bytes, $received);
    }

    public function testAWriteTheDeviceDoesNotTakeInTimeFails(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $caught = null;

        $this->runWithoutFileHook(function () use (&$caught): void {
            $transport = new StreamTransport($this->fifo, 1.0, 0.01);
            $transport->open();

            try {
                $transport->write(str_repeat('x', 200_000));
            } catch (TransportException $exception) {
                $caught = $exception;
            } finally {
                $transport->close();
            }
        });

        $this->assertInstanceOf(TransportException::class, $caught);
        $this->assertSame("The serial device [{$this->fifo}] did not take the bytes written to it within 1 seconds.", $caught->getMessage());
    }

    public function testAReadReturnsWhatHasArrived(): void
    {
        $read = null;

        $this->runWithoutFileHook(function () use (&$read): void {
            $transport = new StreamTransport($this->fifo, 1.0, 0.01);
            $transport->open();

            try {
                $transport->write('abc');
                $read = [$transport->read(), $transport->read()];
            } finally {
                $transport->close();
            }
        });

        $this->assertSame(['abc', ''], $read);
    }

    /**
     * Run the callback in a coroutine with every Swoole hook but the file hooks on.
     */
    protected function runWithoutFileHook(callable $callback): void
    {
        run($callback, SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_STDIO);
    }
}
