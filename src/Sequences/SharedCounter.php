<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Sequences;

use Hypervel\Contracts\Cache\Repository;
use Ipsocode\Serial\Request;

/**
 * A counter whose number lives in a cache store, so every process sharing the store continues one sequence.
 *
 * The caller must hold the cross-process lock while it asks for a number.
 */
class SharedCounter extends Counter
{
    /**
     * Create a new shared counter.
     *
     * @param Repository $store the cache store that holds the number
     * @param string $key the store's key of the number
     */
    public function __construct(
        protected Repository $store,
        protected string $key,
        int $start = 0,
        int $max = 0xFFFF,
    ) {
        parent::__construct($start, $max);
    }

    /**
     * Get the sequence number for the given request.
     */
    public function next(Request $request): int
    {
        $this->current = $this->store->get($this->key, $this->start);

        $number = parent::next($request);

        $this->store->forever($this->key, $this->current);

        return $number;
    }

    /**
     * Start the sequence over.
     */
    public function reset(): void
    {
        parent::reset();

        $this->store->forever($this->key, $this->current);
    }
}
