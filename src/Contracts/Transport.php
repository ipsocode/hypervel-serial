<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

use Ipsocode\Serial\Exceptions\TransportException;

interface Transport
{
    /**
     * Open the device.
     *
     * @throws TransportException
     */
    public function open(): void;

    /**
     * Close the device.
     */
    public function close(): void;

    /**
     * Determine if the device is open.
     */
    public function isOpen(): bool;

    /**
     * Read the bytes that have arrived, up to the given length, without waiting for more.
     *
     * @param positive-int $length
     *
     * @throws TransportException
     */
    public function read(int $length = 1024): string;

    /**
     * Write all of the given bytes to the device.
     *
     * @throws TransportException
     */
    public function write(string $bytes): void;

    /**
     * Discard the bytes that have arrived but not been read.
     *
     * @throws TransportException
     */
    public function flush(): void;
}
