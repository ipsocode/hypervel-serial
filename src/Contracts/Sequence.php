<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

use Ipsocode\Serial\Request;

interface Sequence
{
    /**
     * Get the sequence number for the given request.
     */
    public function next(Request $request): int;

    /**
     * Start the sequence over.
     */
    public function reset(): void;
}
