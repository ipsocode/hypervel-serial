<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Contracts\Result;

/**
 * A result whose status a device reported: 0 is success, a negative status an error, and any
 * other status, such as busy, neither.
 */
class StatusResult implements Result
{
    public function __construct(
        public readonly int $code,
        public readonly string $payload = '',
    ) {
    }

    public function success(): bool
    {
        return $this->code === 0;
    }

    public function error(): bool
    {
        return $this->code < 0;
    }

    public function status(): int
    {
        return $this->code;
    }

    public function data(): string
    {
        return $this->payload;
    }
}
