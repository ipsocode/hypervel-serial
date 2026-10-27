<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Exceptions\SerialException;

readonly class RetryPolicy
{
    /**
     * Create a new retry policy.
     *
     * @param int $times the attempts allowed after the first
     * @param float $delay the seconds to wait between attempts
     */
    public function __construct(
        public int $times,
        public float $delay,
    ) {
    }

    /**
     * Determine if another attempt should follow the outcome of the given attempt.
     */
    public function shouldRetry(int $attempt, Result|SerialException $outcome): bool
    {
        if ($attempt > $this->times) {
            return false;
        }

        return $outcome instanceof SerialException || ! $outcome->success();
    }

    /**
     * Return a copy of the policy that allows the given number of attempts after the first.
     */
    public function withTimes(int $times): self
    {
        return new self($times, $this->delay);
    }
}
