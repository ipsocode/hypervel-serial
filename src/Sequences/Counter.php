<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Sequences;

use Ipsocode\Serial\Contracts\Sequence;
use Ipsocode\Serial\Request;

/**
 * Numbers each request one more than the last, starting over after the largest number.
 */
class Counter implements Sequence
{
    /**
     * The number the next request gets.
     */
    protected int $current;

    /**
     * Create a new counter.
     */
    public function __construct(
        protected int $start = 0,
        protected int $max = 0xFFFF,
    ) {
        $this->current = $start;
    }

    /**
     * Get the sequence number for the given request.
     */
    public function next(Request $request): int
    {
        $number = $this->current;

        $this->current = $number >= $this->max ? $this->start : $number + 1;

        return $number;
    }

    /**
     * Start the sequence over.
     */
    public function reset(): void
    {
        $this->current = $this->start;
    }
}
