<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Checksums\Crc;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Protocols\FramedProtocol;
use Ipsocode\Serial\Request;

/**
 * STX, then sequence, code, sub-code and payload length as 16-bit fields with the payload, in
 * hex, then ETX, a CRC-16/AUG-CCITT over the hex and EOT. A reply carries the request's
 * sequence plus one and a status in place of the code; its sub-code field echoes the code.
 */
class HeaderProtocol extends FramedProtocol
{
    public function __construct()
    {
        parent::__construct("\x02", "\x03", "\x04", new HexAscii, Crc::augCcitt());
    }

    /**
     * Build the bytes the device sends in reply.
     */
    public function reply(int $sequence, int $status, int $code, string $payload = ''): string
    {
        $body = $this->encoding->encode(pack('nnnn', $sequence, $status, $code, strlen($payload)) . $payload);

        return $this->start . $body . $this->end . $this->encoding->encode($this->checksumOf($body)) . $this->terminator;
    }

    public function validate(Frame $frame, Request $request): Result
    {
        if ($frame->header['sequence'] !== $request->sequence + 1) {
            throw new FrameException('The reply is to another request.');
        }

        return new StatusResult($frame->header['status'], $frame->payload);
    }

    protected function header(Request $request): string
    {
        return pack('nnnn', $request->sequence, $request->code, $request->subCode ?? 0, strlen($request->payload));
    }

    protected function headerLength(): int
    {
        return 8;
    }

    protected function parseHeader(string $header): array
    {
        /** @var array{sequence: int, status: int, code: int, length: int} $fields */
        $fields = unpack('nsequence/nstatus/ncode/nlength', $header);

        // The status is a signed 16-bit number: errors are negative.
        if ($fields['status'] >= 0x8000) {
            $fields['status'] -= 0x10000;
        }

        return $fields;
    }
}
