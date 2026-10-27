<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Checksums;

use Ipsocode\Serial\Contracts\Checksum;

/**
 * The bytes XORed together, as NMEA 0183 sentences carry it.
 */
class XorSum implements Checksum
{
    /**
     * Calculate the checksum of the given bytes.
     */
    public function calculate(string $bytes): int
    {
        $checksum = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $checksum ^= ord($bytes[$i]);
        }

        return $checksum;
    }

    /**
     * Get the number of bytes the checksum takes on the wire.
     */
    public function size(): int
    {
        return 1;
    }
}
