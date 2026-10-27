<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

use Ipsocode\Serial\Exceptions\FrameException;

interface Encoding
{
    /**
     * Encode the bytes as they are sent on the wire.
     */
    public function encode(string $bytes): string;

    /**
     * Decode bytes received on the wire.
     *
     * @throws FrameException when the bytes are not valid in this encoding
     */
    public function decode(string $encoded): string;

    /**
     * Get the encoded length of the given number of bytes.
     */
    public function length(int $bytes): int;
}
