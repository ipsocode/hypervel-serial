<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Protocols\FramedProtocol;

/**
 * A protocol with no end or terminator bytes, whose one-byte header, the payload length, is in hex.
 * A header that is not hex fails before any byte is consumed.
 */
class HexLengthProtocol extends FramedProtocol
{
    public function __construct()
    {
        parent::__construct(start: "\x02", encoding: new HexAscii);
    }

    protected function headerLength(): int
    {
        return 1;
    }

    protected function parseHeader(string $header): array
    {
        return ['length' => ord($header)];
    }
}
