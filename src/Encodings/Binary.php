<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Encodings;

use Ipsocode\Serial\Contracts\Encoding;

class Binary implements Encoding
{
    /**
     * Encode the bytes as they are sent on the wire.
     */
    public function encode(string $bytes): string
    {
        return $bytes;
    }

    /**
     * Decode bytes received on the wire.
     */
    public function decode(string $encoded): string
    {
        return $encoded;
    }

    /**
     * Get the encoded length of the given number of bytes.
     */
    public function length(int $bytes): int
    {
        return $bytes;
    }
}
