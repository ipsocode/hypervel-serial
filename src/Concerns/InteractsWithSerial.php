<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Concerns;

use Hypervel\Container\Container;
use Ipsocode\Serial\Contracts\Command;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Exceptions\ReservedException;
use Ipsocode\Serial\Exceptions\SerialException;
use Ipsocode\Serial\Request;

/**
 * @phpstan-require-implements Command
 * @phpstan-ignore trait.unused (user-facing trait for command enums)
 */
trait InteractsWithSerial
{
    /**
     * Send the command and return the device's reply.
     *
     * @param array<string, mixed> $options what payload() builds the payload from
     * @param null|float $timeout the seconds to wait for each reply, in place of the connection's
     * @param null|int $retries the attempts allowed after the first, in place of the connection's
     * @param null|string $owner the owner the command is sent for, which may use the device while it holds a reservation
     *
     * @throws ReservedException when the device is reserved for another owner
     * @throws SerialException when the last attempt gets no valid reply
     */
    public function send(array $options = [], ?float $timeout = null, ?int $retries = null, ?string $owner = null): Result
    {
        return Container::getInstance()->make('serial')
            ->connection($this->serialConnection())
            ->transact(Request::for($this, $options), $timeout, $retries, $owner);
    }

    /**
     * Get the name of the connection the command is sent on, or null for the default connection.
     */
    public function serialConnection(): ?string
    {
        return null;
    }
}
