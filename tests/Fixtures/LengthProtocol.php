<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Checksums\Sum8;
use Ipsocode\Serial\Protocols\FramedProtocol;
use Ipsocode\Serial\Request;

/**
 * A binary protocol with no end or terminator bytes: start bytes, a header of a code byte and
 * a 16-bit payload length, the payload, and an 8-bit sum.
 */
class LengthProtocol extends FramedProtocol
{
    public function __construct()
    {
        parent::__construct(start: "\xAA\x55", checksum: new Sum8);
    }

    protected function header(Request $request): string
    {
        return pack('Cn', $request->code, strlen($request->payload));
    }

    protected function headerLength(): int
    {
        return 3;
    }

    protected function parseHeader(string $header): array
    {
        /** @var array{code: int, length: int} */
        return unpack('Ccode/nlength', $header);
    }
}
