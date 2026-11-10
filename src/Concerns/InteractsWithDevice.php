<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Concerns;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Exceptions\TransportException;

/**
 * What the transports that open a device with a handle, a write deadline and a poll interval share.
 *
 * @property null|resource $handle
 * @property string $device
 * @property float $timeout
 * @property float $pollInterval
 */
trait InteractsWithDevice
{
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
     * Get the time a write that starts now must have finished by.
     */
    protected function writeDeadline(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMicroseconds((int) ($this->timeout * 1_000_000));
    }

    /**
     * Wait before the device is asked to take bytes again.
     *
     * @throws TransportException when the write's deadline has passed
     */
    protected function waitToWrite(CarbonImmutable $deadline): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
            throw new TransportException("The serial device [{$this->device}] did not take the bytes written to it within {$this->timeout} seconds.");
        }

        Sleep::sleep($this->pollInterval);
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
