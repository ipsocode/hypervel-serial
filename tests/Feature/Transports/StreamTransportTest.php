<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature\Transports;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Exceptions\TransportException;
use Ipsocode\Serial\Transports\StreamTransport;

/**
 * Runs in the test's own coroutine, where Swoole's file hook is on, as it is in a Hypervel
 * application. A FIFO opened for reading and writing stands in for the tty: what is written
 * to it comes back to be read.
 */
class StreamTransportTest extends UnitTestCase
{
    protected string $directory;

    protected string $fifo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('serial-stream');
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

    public function testItOpensWritesReadsAndClosesTheDevice(): void
    {
        $transport = new StreamTransport($this->fifo, 1.0, 0.01);

        try {
            $this->assertFalse($transport->isOpen());

            $transport->open();
            $this->assertTrue($transport->isOpen());
            $this->assertSame('', $transport->read());

            $transport->write("\x02hello\x04");

            $this->assertSame("\x02hel", $transport->read(4));
            $this->assertSame("lo\x04", $transport->read());
        } finally {
            $transport->close();
        }

        $this->assertFalse($transport->isOpen());
    }

    public function testFlushDiscardsTheBytesThatHaveArrived(): void
    {
        $transport = new StreamTransport($this->fifo, 1.0, 0.01);
        $transport->open();

        try {
            $transport->write(str_repeat('x', 3000));
            $transport->flush();

            $this->assertSame('', $transport->read());
        } finally {
            $transport->close();
        }
    }

    public function testClosingAClosedDeviceDoesNothing(): void
    {
        $transport = new StreamTransport($this->fifo, 1.0, 0.01);

        $transport->close();

        $this->assertFalse($transport->isOpen());
    }

    public function testOpeningADeviceThatIsNotThereFails(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to open the serial device [' . $this->directory . '/missing].');

        (new StreamTransport($this->directory . '/missing', 1.0, 0.01))->open();
    }

    public function testUsingADeviceThatIsNotOpenFails(): void
    {
        $transport = new StreamTransport($this->fifo, 1.0, 0.01);

        foreach ([fn () => $transport->read(), fn () => $transport->write('x'), fn () => $transport->flush()] as $use) {
            try {
                $use();
                $this->fail('The closed device was used.');
            } catch (TransportException $exception) {
                $this->assertSame("The serial device [{$this->fifo}] is not open.", $exception->getMessage());
            }
        }
    }

    public function testAReadThatFailsThrows(): void
    {
        // Reading the process's own memory from address 0 fails with EIO.
        $transport = new StreamTransport('/proc/self/mem', 1.0, 0.01);
        $transport->open();

        try {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('Unable to read from the serial device [/proc/self/mem].');

            $transport->read();
        } finally {
            $transport->close();
        }
    }

    public function testAWriteThatFailsThrows(): void
    {
        $transport = new StreamTransport('/proc/self/mem', 1.0, 0.01);
        $transport->open();

        try {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('Unable to write to the serial device [/proc/self/mem].');

            $transport->write('x');
        } finally {
            $transport->close();
        }
    }
}
