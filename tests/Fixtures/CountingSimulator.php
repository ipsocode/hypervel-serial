<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Testing\Simulator;

/**
 * A device that is busy for the first requests it gets, then echoes each payload.
 */
class CountingSimulator extends Simulator
{
    /**
     * The requests answered so far.
     *
     * @var array<int, Request>
     */
    public array $requests = [];

    public function __construct(
        protected int $busyFor = 0,
    ) {
    }

    public function respond(Request $request): Result
    {
        $this->requests[] = $request;

        return count($this->requests) <= $this->busyFor
            ? new StatusResult(1)
            : new StatusResult(0, $request->payload);
    }
}
