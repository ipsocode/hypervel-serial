<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Events;

use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;

readonly class FrameReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connection,
        public Request $request,
        public Frame $frame,
    ) {
    }
}
