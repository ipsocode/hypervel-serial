<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Events;

use Ipsocode\Serial\Request;

readonly class FrameSent
{
    /**
     * Create a new event instance.
     *
     * @param string $bytes the bytes written to the device
     */
    public function __construct(
        public string $connection,
        public Request $request,
        public string $bytes,
    ) {
    }
}
