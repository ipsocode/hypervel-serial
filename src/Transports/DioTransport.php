<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Transports;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Contracts\Transport;
use Ipsocode\Serial\Exceptions\TransportException;
use Ipsocode\Serial\Parity;

/**
 * A device opened through ext-dio, which sets its line settings itself.
 */
class DioTransport implements Transport
{
    /**
     * The open device.
     *
     * @var null|resource
     */
    protected mixed $handle = null;

    /**
     * Create a new dio transport.
     *
     * @param bool $flowControl whether the device uses RTS/CTS hardware flow control
     * @param float $timeout the seconds a write may take before it fails
     * @param float $pollInterval the seconds to wait while the device cannot take more bytes
     */
    public function __construct(
        protected string $device,
        protected int $baud,
        protected int $dataBits,
        protected int $stopBits,
        protected Parity $parity,
        protected bool $flowControl,
        protected float $timeout,
        protected float $pollInterval,
    ) {
    }

    /**
     * Open the device and apply its line settings.
     *
     * @throws TransportException
     */
    public function open(): void
    {
        $handle = @dio_open($this->device, O_RDWR | O_NOCTTY | O_NONBLOCK);

        if ($handle === false) {
            throw new TransportException("Unable to open the serial device [{$this->device}].");
        }

        // dio turns hardware flow control on unless told otherwise, so it is always passed, and
        // reads every setting as an integer, so none may be a bool.
        dio_tcsetattr($handle, [
            'baud' => $this->baud,
            'bits' => $this->dataBits,
            'stop' => $this->stopBits,
            'parity' => match ($this->parity) {
                Parity::None => 0,
                Parity::Odd => 1,
                Parity::Even => 2,
            },
            'flow_control' => (int) $this->flowControl,
            'is_canonical' => 0,
        ]);

        $this->handle = $handle;
    }

    /**
     * Close the device.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            dio_close($this->handle);

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
        // dio returns null whenever a read yields nothing, which on a non-blocking device is
        // most often that no bytes have arrived.
        return dio_read($this->handle(), $length) ?? ''; // @phpstan-ignore nullCoalesce.expr (dio_read() returns null when a read yields nothing, which its stub leaves out)
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
            // dio reports a device that cannot take more bytes yet as a failed write, the same
            // as any other failure, so every failure waits for the deadline.
            $written = @dio_write($handle, $bytes);

            if ($written > 0) {
                $bytes = substr($bytes, $written);
            }

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
        while ($this->read() !== '') {
        }
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
