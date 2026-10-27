<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

readonly class Frame
{
    /**
     * Create a new frame.
     *
     * @param string $raw the frame's bytes as they arrived
     * @param string $payload the payload's bytes, decoded
     * @param array<string, int> $header the header's fields, as the protocol parsed them
     */
    public function __construct(
        public string $raw,
        public string $payload = '',
        public array $header = [],
    ) {
    }
}
