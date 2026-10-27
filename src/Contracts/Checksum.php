<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

interface Checksum
{
    /**
     * Calculate the checksum of the given bytes.
     */
    public function calculate(string $bytes): int;

    /**
     * Get the number of bytes the checksum takes on the wire.
     */
    public function size(): int;
}
