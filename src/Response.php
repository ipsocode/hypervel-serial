<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Ipsocode\Serial\Contracts\Result;

/**
 * A frame the protocol accepted as the reply to a request, for a protocol that does not
 * interpret replies itself.
 */
class Response implements Result
{
    /**
     * Create a new response.
     */
    public function __construct(
        public readonly Frame $frame,
    ) {
    }

    /**
     * Determine if the device carried out the request.
     */
    public function success(): bool
    {
        return true;
    }

    /**
     * Determine if the device reported an error.
     */
    public function error(): bool
    {
        return false;
    }

    /**
     * Get the status the device reported.
     */
    public function status(): null
    {
        return null;
    }

    /**
     * Get the payload the device returned.
     */
    public function data(): string
    {
        return $this->frame->payload;
    }
}
