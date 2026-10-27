<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Testing;

use Closure;
use Ipsocode\Serial\Buffer;
use Ipsocode\Serial\Contracts\Protocol;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;

/**
 * The protocol of a connection whose device is simulated. Over a loopback transport, the
 * request's own bytes come back as the reply, and the simulator gives the result, so the
 * connection's sequence, retries, events and logging work as they do with the device.
 */
class SimulatedProtocol implements Protocol
{
    /**
     * Create a new simulated protocol.
     *
     * @param Protocol $protocol the protocol whose bytes the connection sends
     * @param Closure(Request): Result $responder
     */
    public function __construct(
        protected Protocol $protocol,
        protected Closure $responder,
    ) {
    }

    /**
     * Encode the request as the device's protocol does.
     */
    public function encode(Request $request): string
    {
        return $this->protocol->encode($request);
    }

    /**
     * Take the request's own bytes, which the loopback returned, as the reply's frame.
     */
    public function decode(Buffer $buffer): Frame
    {
        return new Frame($buffer->consume($buffer->length()));
    }

    /**
     * Get the simulator's result for the request.
     */
    public function validate(Frame $frame, Request $request): Result
    {
        return ($this->responder)($request);
    }
}
