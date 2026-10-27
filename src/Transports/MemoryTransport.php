<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Transports;

use Ipsocode\Serial\Contracts\Transport;

/**
 * A loopback: what is written comes back to be read.
 */
class MemoryTransport implements Transport
{
    /**
     * Whether the transport is open.
     */
    protected bool $open = false;

    /**
     * The bytes waiting to be read.
     */
    protected string $incoming = '';

    /**
     * Open the device.
     */
    public function open(): void
    {
        $this->open = true;
    }

    /**
     * Close the device.
     */
    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Determine if the device is open.
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Read the bytes that have arrived, up to the given length, without waiting for more.
     *
     * @param positive-int $length
     */
    public function read(int $length = 1024): string
    {
        $bytes = substr($this->incoming, 0, $length);

        $this->incoming = substr($this->incoming, $length);

        return $bytes;
    }

    /**
     * Write all of the given bytes to the device.
     */
    public function write(string $bytes): void
    {
        $this->incoming .= $bytes;
    }

    /**
     * Discard the bytes that have arrived but not been read.
     */
    public function flush(): void
    {
        $this->incoming = '';
    }
}
