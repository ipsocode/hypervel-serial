# Testing

- [Faking connections](#faking-connections)
    - [Assertions](#assertions)
- [Simulating a device](#simulating-a-device)
- [Testing a protocol](#testing-a-protocol)

<a name="faking-connections"></a>
## Faking connections

The `Serial` facade's `fake` method replaces every connection with one that never opens a device. Without arguments, every request succeeds with an empty reply:

```php
use Ipsocode\Serial\Facades\Serial;

Serial::fake();
```

To decide the replies, pass a closure, which receives each request and the name of the connection it was sent on:

```php
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use Ipsocode\Serial\Frame;

Serial::fake(function (Request $request, string $connection) {
    return new Response(new Frame(raw: '', payload: '02.01.38'));
});
```

Or pass a [simulator](#simulating-a-device). A fake connection still numbers its requests, tries again, honors reservations and dispatches events as the real one does; it takes no lock across processes.

<a name="assertions"></a>
### Assertions

The fake records every request it is sent. Assert on them by command, or with a closure that receives the request and the connection's name:

```php
use App\Serial\Info;

Serial::assertSent(Info::Firmware);

Serial::assertSent(Info::SerialNumber, 2);

Serial::assertSent(function (Request $request, string $connection) {
    return $request->command === Info::SerialNumber && $request->payload === pack('n', 3);
});

Serial::assertNotSent(Info::SerialNumber);

Serial::assertNothingSent();

Serial::assertSentCount(3);
```

The `sent` method returns the recorded requests, or those that match a command or closure. An attempt that is tried again is sent again, so it is recorded again.

<a name="simulating-a-device"></a>
## Simulating a device

A simulator answers each request as the device would. Extend `Ipsocode\Serial\Testing\Simulator`, and return a result from its `respond` method:

```php
namespace App\Serial;

use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Testing\Simulator;

class DeviceSimulator extends Simulator
{
    protected int $progress = 0;

    public function respond(Request $request): Result
    {
        return match ($request->command) {
            Info::Firmware => new Reading('02.01.38'),
            Program::Status => ($this->progress += 25) < 100 ? Status::Busy : Status::Success,
            default => Status::Success,
        };
    }
}
```

A simulator may keep the device's state in its properties, as this one keeps the programming progress. To simulate a device that does not reply, throw a `TimeoutException`; to simulate one that is slow, wait with `Hypervel\Support\Sleep`.

Pass the simulator to `Serial::fake`, or name it in a connection's `simulator` setting to work without the hardware:

```php
Serial::fake(new DeviceSimulator);
```

<a name="testing-a-protocol"></a>
## Testing a protocol

`Ipsocode\Serial\Testing\FakeTransport` is a device that answers each write with the next scripted reply: bytes, or a closure that receives the bytes written. Give it to a `Connection` to test a protocol against the real bytes:

```php
use Ipsocode\Serial\Testing\FakeTransport;

$transport = new FakeTransport([
    $firmwareReply,
    fn (string $written) => $this->replyTo($written),
], chunkSize: 4);

// ... a Connection with the transport, then:

$transport->assertWritten($firmwareRequest);
```

A test that captures `$firmwareRequest` and `$firmwareReply` from the real device pins the protocol to the bytes the device actually speaks.

`chunkSize` delivers each reply in pieces of at most that many bytes, as a real device does, so the test shows the protocol finds a frame that arrives in parts. The `written` method returns every write, and `assertNothingWritten` asserts there were none.
