<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

use Ipsocode\Serial\Buffer;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;

interface Protocol
{
    /**
     * Encode the request into the bytes sent to the device.
     */
    public function encode(Request $request): string;

    /**
     * Take the next complete frame from the buffer, or return null until one has arrived.
     *
     * @throws FrameException when the bytes cannot be a valid frame
     */
    public function decode(Buffer $buffer): ?Frame;

    /**
     * Turn the frame the device sent in reply to the request into a result.
     *
     * @throws FrameException when the frame is not a reply to the request
     */
    public function validate(Frame $frame, Request $request): Result;
}
