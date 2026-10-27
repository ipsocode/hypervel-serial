<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Checksums;

use Ipsocode\Serial\Contracts\Checksum;

/**
 * The bytes' sum, modulo 256.
 */
class Sum8 implements Checksum
{
    /**
     * Calculate the checksum of the given bytes.
     */
    public function calculate(string $bytes): int
    {
        return array_sum(array_map(ord(...), str_split($bytes))) & 0xFF;
    }

    /**
     * Get the number of bytes the checksum takes on the wire.
     */
    public function size(): int
    {
        return 1;
    }
}
