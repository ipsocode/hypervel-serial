<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Unit;

use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Buffer;
use Ipsocode\Serial\Checksums\Crc;
use Ipsocode\Serial\Checksums\XorSum;
use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Exceptions\ChecksumException;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Protocols\FramedProtocol;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use Ipsocode\Serial\Tests\Fixtures\HeaderProtocol;
use Ipsocode\Serial\Tests\Fixtures\LengthlessProtocol;
use Ipsocode\Serial\Tests\Fixtures\LengthProtocol;
use LogicException;

class FramedProtocolTest extends UnitTestCase
{
    public function testEncodeLaysOutStartBodyEndChecksumAndTerminator(): void
    {
        $protocol = new HeaderProtocol;
        $request = (new Request(0x06, "\x00\x00", 0x01))->withSequence(0);

        $body = '00000006000100020000';
        $checksum = strtoupper(str_pad(dechex(Crc::augCcitt()->calculate($body)), 4, '0', STR_PAD_LEFT));

        $this->assertSame("\x02{$body}\x03{$checksum}\x04", $protocol->encode($request));
    }

    public function testEncodeWithoutHeaderOrChecksumIsTheFramedPayload(): void
    {
        $protocol = new FramedProtocol(start: '$', terminator: "\r\n");

        $this->assertSame("\$PING\r\n", $protocol->encode(new Request(1, 'PING')));
    }

    public function testDecodeReadsAReplyAndItsHeader(): void
    {
        $protocol = new HeaderProtocol;
        $buffer = new Buffer($reply = $protocol->reply(1, 1, 6, 'abc'));

        $frame = $protocol->decode($buffer);

        $this->assertSame($reply, $frame->raw);
        $this->assertSame('abc', $frame->payload);
        $this->assertSame(['sequence' => 1, 'status' => 1, 'code' => 6, 'length' => 3], $frame->header);
        $this->assertSame(0, $buffer->length());
    }

    public function testDecodeDiscardsNoiseBeforeTheStartAndLeavesWhatFollowsTheFrame(): void
    {
        $protocol = new HeaderProtocol;
        $buffer = new Buffer("noise\x04" . $protocol->reply(1, 1, 6) . "\x02next");

        $this->assertSame('', $protocol->decode($buffer)->payload);
        $this->assertSame("\x02next", $buffer->contents());
    }

    public function testDecodeWaitsForTheRestOfAFrame(): void
    {
        $protocol = new HeaderProtocol;
        $reply = $protocol->reply(1, 1, 6, 'abc');
        $buffer = new Buffer;

        foreach (str_split($reply, 5) as $piece) {
            $this->assertNull($protocol->decode($buffer));

            $buffer->append($piece);
        }

        $this->assertSame('abc', $protocol->decode($buffer)->payload);
    }

    public function testDecodeWithoutStartBytesDiscardsEverything(): void
    {
        $protocol = new HeaderProtocol;
        $buffer = new Buffer('no frame here');

        $this->assertNull($protocol->decode($buffer));
        $this->assertSame(0, $buffer->length());
    }

    public function testDecodeKeepsStartBytesSplitAcrossReads(): void
    {
        $protocol = new LengthProtocol;
        $frame = $protocol->encode(new Request(9, 'hi'));
        $buffer = new Buffer("garbage\xAA");

        $this->assertNull($protocol->decode($buffer));
        $this->assertSame("\xAA", $buffer->contents());

        $buffer->append(substr($frame, 1));

        $this->assertSame('hi', $protocol->decode($buffer)->payload);
    }

    public function testDecodeOfAnEmptyBufferWithoutStartBytesWaits(): void
    {
        $this->assertNull((new FramedProtocol(terminator: "\n"))->decode(new Buffer));
    }

    public function testDecodeRejectsAFrameThatDoesNotMatchItsChecksum(): void
    {
        $protocol = new HeaderProtocol;
        $reply = $protocol->reply(1, 1, 6, 'abc');
        $buffer = new Buffer(substr_replace($reply, 'F', -3, 1));

        $this->expectException(ChecksumException::class);
        $this->expectExceptionMessage('does not match its checksum');

        try {
            $protocol->decode($buffer);
        } finally {
            $this->assertSame(0, $buffer->length());
        }
    }

    public function testDecodeAcceptsAChecksumInLowercase(): void
    {
        $protocol = new HeaderProtocol;
        $reply = $protocol->reply(1, 1, 6, 'abc');
        $lower = substr($reply, 0, -5) . strtolower(substr($reply, -5, 4)) . "\x04";

        $this->assertSame('abc', $protocol->decode(new Buffer($lower))->payload);
    }

    public function testDecodeRejectsAFrameWhoseEndByteIsMissing(): void
    {
        $protocol = new HeaderProtocol;
        $reply = $protocol->reply(1, 1, 6);

        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('does not end with its end and terminator bytes');

        $protocol->decode(new Buffer(str_replace("\x03", 'X', $reply)));
    }

    public function testDecodeRejectsAFrameTooShortForItsFraming(): void
    {
        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('is too short to hold its framing');

        (new HeaderProtocol)->decode(new Buffer("\x02\x03\x04"));
    }

    public function testDecodeRejectsAFrameTooShortForItsHeader(): void
    {
        $body = '0001';
        $checksum = strtoupper(bin2hex(pack('n', Crc::augCcitt()->calculate($body))));

        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('is too short to hold its header');

        (new HeaderProtocol)->decode(new Buffer("\x02{$body}\x03{$checksum}\x04"));
    }

    public function testDecodeRejectsAPayloadLongerOrShorterThanItsHeaderGives(): void
    {
        $protocol = new HeaderProtocol;
        $body = strtoupper(bin2hex(pack('nnnn', 1, 1, 6, 5) . 'abc'));
        $checksum = strtoupper(bin2hex(pack('n', Crc::augCcitt()->calculate($body))));

        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('holds a payload of 3 bytes, not the 5 its header gives');

        $protocol->decode(new Buffer("\x02{$body}\x03{$checksum}\x04"));
    }

    public function testDecodeFindsAFrameByItsEndByteAndTheChecksumAfterIt(): void
    {
        $protocol = new FramedProtocol(start: '$', end: '*', encoding: new HexAscii, checksum: new XorSum);
        $frame = $protocol->encode(new Request(1, 'GPS'));
        $buffer = new Buffer;

        $buffer->append(substr($frame, 0, -1));
        $this->assertNull($protocol->decode($buffer));

        $buffer->append(substr($frame, -1) . '$');
        $this->assertSame('GPS', $protocol->decode($buffer)->payload);
        $this->assertSame('$', $buffer->contents());
    }

    public function testDecodeWaitsForTheEndByte(): void
    {
        $this->assertNull((new FramedProtocol(start: '$', end: '*'))->decode(new Buffer('$GPS')));
    }

    public function testDecodeFindsABinaryFrameByThePayloadLengthItsHeaderGives(): void
    {
        $protocol = new LengthProtocol;
        $frame = $protocol->encode(new Request(9, "\xAA\x55\x00"));
        $buffer = new Buffer("\xAA\x55");

        $this->assertNull($protocol->decode($buffer));

        $buffer->append(substr($frame, 2, 3));
        $this->assertNull($protocol->decode($buffer));

        $buffer->append(substr($frame, 5));
        $decoded = $protocol->decode($buffer);

        $this->assertSame("\xAA\x55\x00", $decoded->payload);
        $this->assertSame(['code' => 9, 'length' => 3], $decoded->header);
    }

    public function testDecodeWithoutEndBytesOrAPayloadLengthCannotFindAFrame(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs a header that gives the payload length');

        (new LengthlessProtocol)->decode(new Buffer("\x01\x02"));
    }

    public function testValidateReturnsAResponse(): void
    {
        $protocol = new FramedProtocol(terminator: "\n");
        $frame = $protocol->decode(new Buffer("OK\n"));

        $response = $protocol->validate($frame, new Request(1));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('OK', $response->data());
    }
}
