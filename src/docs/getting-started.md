# Getting started

- [Installation](#installation)
- [Describing a device](#describing-a-device)
    - [Commands](#commands)
    - [The protocol](#the-protocol)
- [Sending a command](#sending-a-command)

<a name="installation"></a>
## Installation

Install the package with Composer, then publish its configuration file:

```sh
composer require ipsocode/hypervel-serial
php artisan vendor:publish --tag=serial-config
```

Package discovery registers the service provider and the `Serial` facade. The configuration lives in `config/serial.php`, whose `default` connection opens the device named by the `SERIAL_DEVICE` environment variable:

```ini
SERIAL_DEVICE=/dev/ttyUSB0
SERIAL_BAUD=115200
```

The default `stream` transport opens the device as a file, so set its line settings before the application starts, as [Configuration](configuration.md#the-stream-transport) describes.

<a name="describing-a-device"></a>
## Describing a device

The package knows how to move bytes and hold the device; what the bytes mean belongs to your application. You describe a device with its commands and its protocol.

<a name="commands"></a>
### Commands

A command implements `Ipsocode\Serial\Contracts\Command`: the code the device knows it by, an optional sub-command code, and the payload it carries. A backed enum is a natural fit, and the `InteractsWithSerial` trait gives it a `send` method:

```php
namespace App\Serial;

use Ipsocode\Serial\Concerns\InteractsWithSerial;
use Ipsocode\Serial\Contracts\Command;

enum Info: int implements Command
{
    use InteractsWithSerial;

    case Firmware = 0;
    case SerialNumber = 1;

    public function code(): int
    {
        return 0x04;
    }

    public function subCode(): ?int
    {
        return $this->value;
    }

    public function payload(array $options = []): string
    {
        return isset($options['slot']) ? pack('n', $options['slot']) : '';
    }
}
```

A payload is the raw bytes. The protocol encodes them for the wire, so a command never builds hex strings itself.

<a name="the-protocol"></a>
### The protocol

A protocol turns a request into bytes and the device's reply into a result. Most devices frame their messages with start and end bytes, a header and a checksum, which `Ipsocode\Serial\Protocols\FramedProtocol` covers; you extend it with your device's bytes and header:

```php
namespace App\Serial;

use Ipsocode\Serial\Checksums\Crc;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Exceptions\FrameException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Protocols\FramedProtocol;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;

class DeviceProtocol extends FramedProtocol
{
    public function __construct()
    {
        parent::__construct(start: "\x02", end: "\x03", terminator: "\x04", encoding: new HexAscii, checksum: Crc::augCcitt());
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
        return unpack('nsequence/nstatus/ncode/nlength', $header);
    }

    public function validate(Frame $frame, Request $request): Result
    {
        if ($frame->header['sequence'] !== $request->sequence + 1) {
            throw new FrameException('The device replied to another request.');
        }

        return Status::tryFrom($frame->header['status']) ?? new Response($frame);
    }
}
```

The default `validate` accepts every frame, so a reply that arrives after its request timed out would be returned as the next request's result. This `validate` rejects a reply whose sequence does not follow the request's, and the connection skips it. It also turns the device's status into the result: `Status` stands for a backed enum of your device's status codes, as [Protocols](protocols.md#turning-replies-into-results) describes, so a reply that reports an error is no longer a success.

Name the protocol in the connection's configuration:

```php
'protocol' => App\Serial\DeviceProtocol::class,
```

[Protocols](protocols.md) covers the rest, including turning a reply into a result of your own.

<a name="sending-a-command"></a>
## Sending a command

Call `send` on a command. It returns the device's reply as an `Ipsocode\Serial\Contracts\Result`:

```php
use App\Serial\Info;

$result = Info::Firmware->send();

if ($result->success()) {
    $version = $result->data();
}
```

The options you pass to `send` reach the command's `payload` method:

```php
$result = Info::SerialNumber->send(['slot' => 3]);
```

A transaction that gets no valid reply, even after its retries, throws an `Ipsocode\Serial\Exceptions\SerialException`. [Transactions](transactions.md) explains what is retried, and how to send several requests without another coroutine's coming between them.
