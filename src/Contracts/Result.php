<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

use UnitEnum;

interface Result
{
    /**
     * Determine if the device carried out the request.
     *
     * A result that is not successful is retried while the connection has attempts left.
     */
    public function success(): bool;

    /**
     * Determine if the device reported an error.
     *
     * A result can be neither successful nor an error, such as a device that is still busy.
     */
    public function error(): bool;

    /**
     * Get the status the device reported, or null when the protocol has none.
     */
    public function status(): int|string|UnitEnum|null;

    /**
     * Get the data the device returned.
     */
    public function data(): mixed;
}
