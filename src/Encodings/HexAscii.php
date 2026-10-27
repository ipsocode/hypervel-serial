<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Encodings;

use Ipsocode\Serial\Contracts\Encoding;
use Ipsocode\Serial\Exceptions\FrameException;

/**
 * Each byte as two hex digits, so a frame's start, end and terminator bytes never occur inside it.
 */
class HexAscii implements Encoding
{
    /**
     * Create a new hex encoding.
     */
    public function __construct(
        protected bool $uppercase = true,
    ) {
    }

    /**
     * Encode the bytes as they are sent on the wire.
     */
    public function encode(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return $this->uppercase ? strtoupper($hex) : $hex;
    }

    /**
     * Decode bytes received on the wire, in either case.
     *
     * @throws FrameException when the bytes are not pairs of hex digits
     */
    public function decode(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        if (strlen($encoded) % 2 !== 0 || ! ctype_xdigit($encoded)) {
            throw new FrameException('The frame holds [' . bin2hex($encoded) . '], which is not pairs of hex digits.');
        }

        return pack('H*', $encoded);
    }

    /**
     * Get the encoded length of the given number of bytes.
     */
    public function length(int $bytes): int
    {
        return $bytes * 2;
    }
}
