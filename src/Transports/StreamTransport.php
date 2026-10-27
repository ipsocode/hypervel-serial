<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Transports;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Exceptions\TransportException;

/**
 * A device opened as a PHP stream. PHP cannot set a tty's line settings, so they are set
 * before the application starts, with `stty`.
 *
 * Errors are silenced where they are checked, so a failed read or write becomes a
 * TransportException, which reopens the device, rather than the error handler's exception.
 */
class StreamTransport implements Transport
{
    /**
     * The open device.
     *
     * @var null|resource
     */
    protected mixed $handle = null;

    /**
     * Create a new stream transport.
     *
     * @param float $timeout the seconds a write may take before it fails
     * @param float $pollInterval the seconds to wait while the device cannot take more bytes
     */
    public function __construct(
        protected string $device,
        protected float $timeout,
        protected float $pollInterval,
    ) {
    }

    /**
     * Open the device.
     *
     * @throws TransportException
     */
    public function open(): void
    {
        // The `n` mode opens the device non-blocking, so opening a serial port never waits for
        // its carrier signal.
        $handle = @fopen($this->device, 'r+bn');

        if ($handle === false) {
            throw new TransportException("Unable to open the serial device [{$this->device}].");
        }

        stream_set_blocking($handle, false);

        $this->handle = $handle;
    }

    /**
     * Close the device.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);

            $this->handle = null;
        }
    }

    /**
     * Determine if the device is open.
     */
    public function isOpen(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Read the bytes that have arrived, up to the given length, without waiting for more.
     *
     * @param positive-int $length
     *
     * @throws TransportException
     */
    public function read(int $length = 1024): string
    {
        $handle = $this->handle();
        $bytes = '';

        // Under Swoole's file hook a read of a tty waits until it has every byte asked for, so
        // each read asks for one byte, once select() says one has arrived.
        while (strlen($bytes) < $length && $this->readable($handle)) {
            $byte = @fread($handle, 1);

            if ($byte === false || $byte === '') {
                throw new TransportException("Unable to read from the serial device [{$this->device}].");
            }

            $bytes .= $byte;
        }

        return $bytes;
    }

    /**
     * Write all of the given bytes to the device.
     *
     * @throws TransportException
     */
    public function write(string $bytes): void
    {
        $handle = $this->handle();
        $deadline = CarbonImmutable::now()->addMicroseconds((int) ($this->timeout * 1_000_000));

        while ($bytes !== '') {
            $written = @fwrite($handle, $bytes);

            if ($written === false) {
                throw new TransportException("Unable to write to the serial device [{$this->device}].");
            }

            $bytes = substr($bytes, $written);

            if ($bytes === '') {
                return;
            }

            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw new TransportException("The serial device [{$this->device}] did not take the bytes written to it within {$this->timeout} seconds.");
            }

            Sleep::sleep($this->pollInterval);
        }
    }

    /**
     * Discard the bytes that have arrived but not been read.
     *
     * @throws TransportException
     */
    public function flush(): void
    {
        while ($this->read() !== '');
    }

    /**
     * Determine if a byte has arrived, without waiting for one.
     *
     * @param resource $handle
     */
    protected function readable(mixed $handle): bool
    {
        $read = [$handle];
        $write = $except = null;

        return stream_select($read, $write, $except, 0) > 0;
    }

    /**
     * Get the open device.
     *
     * @return resource
     *
     * @throws TransportException
     */
    protected function handle(): mixed
    {
        return $this->handle ?? throw new TransportException("The serial device [{$this->device}] is not open.");
    }
}
