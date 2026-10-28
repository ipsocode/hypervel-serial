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
     * The text of the warning dio_write() raises for a device that cannot take more bytes yet.
     */
    protected const WOULD_BLOCK_MESSAGE = 'Resource temporarily unavailable';

    /**
     * The text of the warning dio_write() raises for an interrupted write.
     */
    protected const INTERRUPTED_MESSAGE = 'Interrupted system call';

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
        $handle = $this->handle();

        $bytes = dio_read($handle, $length);

        if ($bytes !== null) { // @phpstan-ignore notIdentical.alwaysTrue (dio_read() returns null when a read yields nothing, and PHPStan's function map types it as string, without that null)
            return $bytes;
        }

        // dio returns null for any read(2) result of 0 or less, so "no bytes yet" cannot be told
        // from the end of file or I/O error of a device that was unplugged. An unplugged device
        // has no link left to its node; one that is merely quiet still does.
        // @phpstan-ignore deadCode.unreachable (follows from the dio_read() null that PHPStan's function map omits)
        if ((@dio_stat($handle)['nlink'] ?? 0) === 0) {
            throw new TransportException("The serial device [{$this->device}] is no longer available.");
        }

        return '';
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
            $warning = '';

            // The application's error handler returns without setting error_get_last(), so the
            // warning is caught here instead.
            set_error_handler(function (int $level, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            });

            try {
                $written = dio_write($handle, $bytes);
            } finally {
                restore_error_handler();
            }

            if ($written > 0) {
                $bytes = substr($bytes, $written);
            } elseif ($written < 0) {
                $this->failUnlessWouldBlock($warning);
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
        while ($this->read() !== '');
    }

    /**
     * Throw if the warning of a failed write names anything but a device that is not ready yet.
     *
     * @throws TransportException
     */
    protected function failUnlessWouldBlock(string $warning): void
    {
        if ($warning === '' || str_contains($warning, self::WOULD_BLOCK_MESSAGE) || str_contains($warning, self::INTERRUPTED_MESSAGE)) {
            return;
        }

        throw new TransportException("Unable to write to the serial device [{$this->device}]: {$warning}");
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
