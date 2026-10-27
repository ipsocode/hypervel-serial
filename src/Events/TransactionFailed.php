<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Events;

use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Exceptions\SerialException;
use Ipsocode\Serial\Request;

readonly class TransactionFailed
{
    /**
     * Create a new event instance.
     *
     * @param int $attempts the attempts made, the first included
     * @param Result|SerialException $outcome the last attempt's result, or why it failed
     */
    public function __construct(
        public string $connection,
        public Request $request,
        public int $attempts,
        public Result|SerialException $outcome,
    ) {
    }
}
