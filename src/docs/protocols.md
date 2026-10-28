# Protocols

- [Introduction](#introduction)
- [Framed protocols](#framed-protocols)
    - [Finding a frame](#finding-a-frame)
    - [Headers](#headers)
    - [Turning replies into results](#turning-replies-into-results)
- [Encodings](#encodings)
- [Checksums](#checksums)
- [Writing a protocol](#writing-a-protocol)

<a name="introduction"></a>
## Introduction

A protocol, which implements `Ipsocode\Serial\Contracts\Protocol`, does three things: `encode` turns a request into the bytes written to the device, `decode` takes a complete frame from the bytes read so far, and `validate` turns the frame the device replied with into a result.

<a name="framed-protocols"></a>
## Framed protocols

`Ipsocode\Serial\Protocols\FramedProtocol` lays a frame out as start bytes, a body, end bytes, a checksum and terminator bytes. The body is the header followed by the payload, encoded, and the checksum covers the encoded body. Every part but the body is optional:

```php
use Ipsocode\Serial\Checksums\Crc;
use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Protocols\FramedProtocol;

// STX, the body in hex, ETX, a CRC-16 in hex, EOT.
new FramedProtocol(start: "\x02", end: "\x03", terminator: "\x04", encoding: new HexAscii, checksum: Crc::augCcitt());

// A line of text.
new FramedProtocol(terminator: "\r\n");
```

You will usually extend it, so the connection's `protocol` setting can name your class.

<a name="finding-a-frame"></a>
### Finding a frame

Bytes arrive in pieces, so `decode` is called as each piece arrives, and returns nothing until a whole frame has. It discards anything before the start bytes, which drops line noise and the tail of an earlier reply. It then finds the frame's end by its terminator, or by its end bytes followed by the checksum.

A binary frame's payload may contain any byte, the terminator's included, so a binary protocol usually gives the payload's length in its header instead of ending with a delimiter. When a frame has neither end nor terminator bytes, `FramedProtocol` reads the length from the header's `length` field, described below, and takes exactly that many bytes. A text encoding such as `HexAscii` never produces the control bytes that frame it, so delimiters are safe there.

A frame that cannot be valid makes `decode` throw an `Ipsocode\Serial\Exceptions\FrameException`: one too short for its framing, one whose end bytes are not where they belong, one that is not valid in its encoding, or one whose payload is longer or shorter than its header gives. A checksum that does not match throws a `ChecksumException`, which extends it. The connection treats either as a failed attempt, and tries again.

<a name="headers"></a>
### Headers

A header is described by three methods. `header` builds the bytes sent before a request's payload, before encoding; `headerLength` gives the length of a reply's header; and `parseHeader` turns a reply's header into named fields:

```php
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
    return unpack('nsequence/nstatus/ncode/nlength', $header);
}
```

A `length` field gives the payload's length in bytes. The frame's payload must match it, and it finds the frame's end when there are no end or terminator bytes. The fields are on the frame's `header` property for `validate`.

<a name="turning-replies-into-results"></a>
### Turning replies into results

`FramedProtocol::validate` returns an `Ipsocode\Serial\Response`, which is always a success and carries the payload as its data. The default `validate` accepts every frame, so it cannot tell a late reply to an earlier request from this request's own reply: a protocol for a device that can reply late must check the sequence itself. A device that reports a status in its replies deserves a result of its own: override `validate`, and return any class that implements `Ipsocode\Serial\Contracts\Result`. A backed enum of the device's status codes works well for statuses without data:

```php
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;

public function validate(Frame $frame, Request $request): Result
{
    if ($frame->header['sequence'] !== $request->sequence + 1) {
        throw new FrameException('The device replied to another request.');
    }

    return Status::tryFrom($frame->header['status']) ?? new Reading($frame->payload);
}
```

A result's `success` says whether the device carried out the request, and `error` whether it reported an error; a result can be neither, such as a device that is still busy. A result that is not a success is tried again while the connection has attempts left. Throw a `FrameException` from `validate` for a frame that is not a reply to the request at all, such as one that answers another request.

<a name="encodings"></a>
## Encodings

An encoding, which implements `Ipsocode\Serial\Contracts\Encoding`, turns the body's and the checksum's bytes into what is sent on the wire.

| Encoding | On the wire |
|---|---|
| `Ipsocode\Serial\Encodings\Binary` | The bytes as they are |
| `Ipsocode\Serial\Encodings\HexAscii` | Each byte as two hex digits, in uppercase, or lowercase with `new HexAscii(uppercase: false)`; replies may use either |

<a name="checksums"></a>
## Checksums

A checksum, which implements `Ipsocode\Serial\Contracts\Checksum`, calculates a number over the encoded body. `FramedProtocol` writes it most significant byte first, in as many bytes as its `size` gives; override its `checksumOf` method for a device that wants another order.

`Ipsocode\Serial\Checksums\Crc` calculates any cyclic redundancy check from 8 to 32 bits wide, in the parameters of the [CRC catalogue](https://reveng.sourceforge.io/crc-catalogue/), and has the common ones ready:

| Method | Catalogue name |
|---|---|
| `Crc::ccittFalse()` | CRC-16/IBM-3740, known as CRC-16/CCITT-FALSE |
| `Crc::xmodem()` | CRC-16/XMODEM |
| `Crc::augCcitt()` | CRC-16/SPI-FUJITSU, known as CRC-16/AUG-CCITT |
| `Crc::modbus()` | CRC-16/MODBUS |
| `Crc::crc32()` | CRC-32/ISO-HDLC |

Any other CRC takes its parameters from the catalogue:

```php
// CRC-8/SMBUS
new Crc(width: 8, polynomial: 0x07);

// CRC-16/KERMIT
new Crc(width: 16, polynomial: 0x1021, reflectInput: true, reflectOutput: true);
```

The package also has `Ipsocode\Serial\Checksums\Lrc`, the longitudinal redundancy check of Modbus ASCII, `XorSum`, the bytes XORed together as NMEA sentences carry it, and `Sum8`, the bytes' sum modulo 256.

<a name="writing-a-protocol"></a>
## Writing a protocol

A device whose frames do not fit `FramedProtocol`'s layout gets a protocol of its own that implements `Ipsocode\Serial\Contracts\Protocol`. Its `decode` receives an `Ipsocode\Serial\Buffer` holding every byte read so far: look into it with `indexOf` and `peek`, take a frame off its front with `consume` once one is complete, and return `null` until then.
