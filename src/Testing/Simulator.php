<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Testing;

use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Request;

/**
 * A device emulated in PHP, which answers each request with the result the real device would
 * give. A connection whose `simulator` setting names one, or `Serial::fake()` given one, sends
 * every request to it instead of to a device.
 */
abstract class Simulator
{
    /**
     * Answer the request as the device would.
     *
     * Throwing a SerialException, such as a TimeoutException, fails the attempt as a device that
     * did not reply would.
     */
    abstract public function respond(Request $request): Result;
}
