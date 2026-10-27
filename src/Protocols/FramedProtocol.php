<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Protocols;

use Ipsocode\Serial\Buffer;
use Ipsocode\Serial\Contracts\Checksum;
use Ipsocode\Serial\Contracts\Encoding;
use Ipsocode\Serial\Contracts\Protocol;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Encodings\Binary;
use Ipsocode\Serial\Exceptions\ChecksumException;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use LogicException;

/**
 * Frames laid out as start, body, end, checksum and terminator, where the body is the header
 * followed by the payload, encoded, and the checksum covers the encoded body.
 *
 * A frame is found by its end or terminator bytes, or, with neither, by the payload length its
 * header gives under the `length` key. Subclasses define the header with header(),
 * headerLength() and parseHeader(), and interpret replies in validate().
 */
class FramedProtocol implements Protocol
{
    /**
     * Create a new framed protocol.
     */
    public function __construct(
        protected string $start = '',
        protected string $end = '',
        protected string $terminator = '',
        protected Encoding $encoding = new Binary,
        protected ?Checksum $checksum = null,
    ) {
    }

    /**
     * Encode the request into the bytes sent to the device.
     */
    public function encode(Request $request): string
    {
        $body = $this->encoding->encode($this->header($request) . $request->payload);

        return $this->start . $body . $this->end . $this->encoding->encode($this->checksumOf($body)) . $this->terminator;
    }

    /**
     * Take the next complete frame from the buffer, or return null until one has arrived.
     *
     * @throws FrameException when the bytes cannot be a valid frame
     */
    public function decode(Buffer $buffer): ?Frame
    {
        if (! $this->synchronize($buffer)) {
            return null;
        }

        $length = $this->end === '' && $this->terminator === ''
            ? $this->lengthFromHeader($buffer)
            : $this->lengthFromDelimiters($buffer);

        if ($length === null) {
            return null;
        }

        $raw = $buffer->consume($length);
        $checksumLength = $this->checksumLength();
        $bodyLength = $length - strlen($this->start) - strlen($this->end) - $checksumLength - strlen($this->terminator);

        if ($bodyLength < 0) {
            throw new FrameException('The frame [' . bin2hex($raw) . '] is too short to hold its framing.');
        }

        $body = substr($raw, strlen($this->start), $bodyLength);
        $trailer = substr($raw, strlen($this->start) + $bodyLength);

        if (! str_starts_with($trailer, $this->end) || ! str_ends_with($trailer, $this->terminator)) {
            throw new FrameException('The frame [' . bin2hex($raw) . '] does not end with its end and terminator bytes.');
        }

        if ($this->checksum !== null && $this->encoding->decode(substr($trailer, strlen($this->end), $checksumLength)) !== $this->checksumOf($body)) {
            throw new ChecksumException('The frame [' . bin2hex($raw) . '] does not match its checksum.');
        }

        $bytes = $this->encoding->decode($body);

        if (strlen($bytes) < $this->headerLength()) {
            throw new FrameException('The frame [' . bin2hex($raw) . '] is too short to hold its header.');
        }

        $header = $this->parseHeader(substr($bytes, 0, $this->headerLength()));
        $payload = substr($bytes, $this->headerLength());

        if (isset($header['length']) && strlen($payload) !== $header['length']) {
            throw new FrameException('The frame [' . bin2hex($raw) . '] holds a payload of ' . strlen($payload) . " bytes, not the {$header['length']} its header gives.");
        }

        return new Frame($raw, $payload, $header);
    }

    /**
     * Turn the frame the device sent in reply to the request into a result.
     *
     * @throws FrameException when the frame is not a reply to the request
     */
    public function validate(Frame $frame, Request $request): Result
    {
        return new Response($frame);
    }

    /**
     * Build the header sent before the request's payload, as bytes before encoding.
     */
    protected function header(Request $request): string
    {
        return '';
    }

    /**
     * Get the length of a reply's header, in bytes before encoding.
     */
    protected function headerLength(): int
    {
        return 0;
    }

    /**
     * Parse a reply's header into its fields.
     *
     * A `length` field gives the payload's length in bytes, which the frame's payload must
     * match, and which finds the frame's end when it has no end or terminator bytes.
     *
     * @return array<string, int>
     *
     * @throws FrameException when the header is not valid
     */
    protected function parseHeader(string $header): array
    {
        return [];
    }

    /**
     * Calculate the checksum of the encoded body, as bytes before encoding, most significant first.
     */
    protected function checksumOf(string $body): string
    {
        if ($this->checksum === null) {
            return '';
        }

        return substr(pack('J', $this->checksum->calculate($body)), -$this->checksum->size());
    }

    /**
     * Get the length of the checksum on the wire.
     */
    protected function checksumLength(): int
    {
        return $this->checksum === null ? 0 : $this->encoding->length($this->checksum->size());
    }

    /**
     * Discard the bytes before the next start bytes, and determine if a frame may follow.
     */
    protected function synchronize(Buffer $buffer): bool
    {
        if ($this->start === '') {
            return $buffer->length() > 0;
        }

        $position = $buffer->indexOf($this->start);

        if ($position === null) {
            // Keep what may be the beginning of start bytes split across two reads.
            $buffer->consume($buffer->length() - strlen($this->start) + 1);

            return false;
        }

        $buffer->consume($position);

        return true;
    }

    /**
     * Get the length of the frame at the start of the buffer from its end or terminator bytes.
     */
    protected function lengthFromDelimiters(Buffer $buffer): ?int
    {
        if ($this->terminator !== '') {
            $position = $buffer->indexOf($this->terminator, strlen($this->start));

            return $position === null ? null : $position + strlen($this->terminator);
        }

        $position = $buffer->indexOf($this->end, strlen($this->start));

        if ($position === null) {
            return null;
        }

        $length = $position + strlen($this->end) + $this->checksumLength();

        return $buffer->length() >= $length ? $length : null;
    }

    /**
     * Get the length of the frame at the start of the buffer from the payload length its header gives.
     *
     * @throws FrameException when the header is not valid
     * @throws LogicException when the header gives no payload length
     */
    protected function lengthFromHeader(Buffer $buffer): ?int
    {
        $headerLength = $this->encoding->length($this->headerLength());

        if ($buffer->length() < strlen($this->start) + $headerLength) {
            return null;
        }

        $header = $this->parseHeader($this->encoding->decode($buffer->peek($headerLength, strlen($this->start))));

        if (! isset($header['length'])) {
            throw new LogicException('A protocol whose frames have no end or terminator bytes needs a header that gives the payload length.');
        }

        $length = strlen($this->start) + $this->encoding->length($this->headerLength() + $header['length']) + $this->checksumLength();

        return $buffer->length() >= $length ? $length : null;
    }
}
