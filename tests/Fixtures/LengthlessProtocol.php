<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Protocols\FramedProtocol;

/**
 * A protocol with neither end or terminator bytes nor a payload length, so no frame can be found.
 */
class LengthlessProtocol extends FramedProtocol
{
    protected function headerLength(): int
    {
        return 1;
    }

    protected function parseHeader(string $header): array
    {
        return ['code' => ord($header)];
    }
}
