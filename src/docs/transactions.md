# Transactions

- [Sending a request](#sending-a-request)
    - [Results and failures](#results-and-failures)
    - [Timeouts and retries](#timeouts-and-retries)
- [Holding the device](#holding-the-device)
- [Reservations](#reservations)
- [Events](#events)
- [Running on Hypervel](#running-on-hypervel)

<a name="sending-a-request"></a>
## Sending a request

A transaction sends one request and waits for the device's reply. A command's `send` method runs one on its connection, and a connection's `transact` method runs one for any request:

```php
use Ipsocode\Serial\Facades\Serial;
use Ipsocode\Serial\Request;

$result = Serial::connection()->transact(new Request(code: 0x04, payload: "\x00\x03", subCode: 1));
```

The connection opens its device on the first transaction, and discards any bytes that arrived before the request, so a late reply to an earlier request is never mistaken for this one's.

A command sends on the default connection. To send it on another, override the command's `serialConnection` method:

```php
public function serialConnection(): ?string
{
    return 'charger';
}
```

<a name="results-and-failures"></a>
### Results and failures

A transaction returns the result the protocol made of the device's reply, whether or not it is a success, once the device has replied or the connection has no attempts left. When the last attempt gets no valid reply at all, the transaction throws the reason, an `Ipsocode\Serial\Exceptions\SerialException`:

| Exception | When |
|---|---|
| `TimeoutException` | No complete frame arrived in time |
| `FrameException` | The bytes could not be a valid frame, or the protocol rejected the frame as a reply |
| `ChecksumException` | The frame did not match its checksum |
| `TransportException` | The device could not be opened, read or written; the next attempt opens it again, so a device that is unplugged and plugged back in recovers |
| `ReservedException` | The device is [reserved](#reservations) for another owner; this one is thrown at once, without an attempt |

<a name="timeouts-and-retries"></a>
### Timeouts and retries

An attempt that gets no valid reply, or a reply that is not a success, is tried again, as the connection's `retries` and `retry_delay` settings allow. A transaction can wait longer for a slow command, or skip the retries of one that must not be sent twice:

```php
$result = Program::Start->send(['slot' => 3], timeout: 10.0, retries: 0);

$result = Serial::connection()->transact($request, timeout: 10.0, retries: 0);
```

<a name="holding-the-device"></a>
## Holding the device

Transactions from different coroutines never interleave: each holds the device from its request until its reply. Some exchanges take several transactions, such as a file sent in blocks, where a request from elsewhere must not come between them. Run them in a callback passed to `exclusive`:

```php
Serial::connection()->exclusive(function () use ($blocks) {
    $size = Hex::Start->send()->data();

    foreach ($blocks as $block) {
        Hex::Next->send(['block' => $block]);
    }

    return Hex::Checksum->send();
});
```

Transactions the callback runs go straight through, and any other coroutine's wait until the callback returns. A coroutine the callback starts does not share the hold, so send from the callback itself. When the connection has a [lock across processes](configuration.md#a-lock-across-processes), each hold takes it too.

<a name="reservations"></a>
## Reservations

A hold lasts as long as one callback. Some work holds the device across many separate calls instead, such as a calibration that sends a reading request every few seconds from a timer until it is stopped. Reserve the device for an owner, and send that work's requests for the same owner:

```php
$connection = Serial::connection();

if (! $connection->reserve(Calibration::class)) {
    // The device is reserved for someone else.
}

// Later, from any coroutine:
$reading = Status::Measurements->send(owner: Calibration::class);

// When the calibration ends:
$connection->release(Calibration::class);
```

While the device is reserved, a transaction for no owner, or another owner, throws a `ReservedException` at once, and `reserve` for another owner returns `false`. The owner is any string that names the work, such as a class or a slot number.

A reservation can lapse on its own, so work that stops without releasing it does not keep the device forever. Give `reserve` the seconds it lasts after the owner's last transaction; each transaction for the owner, or `reserve` again, starts the time over:

```php
$connection->reserve('slot-3', seconds: 10);
```

`reservedBy` returns the owner the device is reserved for, or `null`. A reservation belongs to the connection in the process that made it.

<a name="events"></a>
## Events

The connection dispatches these events, each with the connection's name and the request:

| Event | When | Also carries |
|---|---|---|
| `Ipsocode\Serial\Events\FrameSent` | Bytes were written to the device | The `bytes` |
| `Ipsocode\Serial\Events\FrameReceived` | A complete frame was read | The `frame` |
| `Ipsocode\Serial\Events\TransactionFailed` | A transaction's last attempt did not succeed | The `attempts` made, and the `outcome`: the last result, or the exception about to be thrown |

A connection only builds an event when something listens for it. The connection's [log channel](configuration.md#logging) records the same without a listener.

<a name="running-on-hypervel"></a>
## Running on Hypervel

A connection belongs to the worker or process that resolved it, and every coroutine in it shares the connection, as they share the device. Waiting for a reply yields to the other coroutines, so a transaction never blocks the worker.

A device can be opened by one process at a time without confusion, so give the device to one process, such as a Hypervel server process, and have the other workers ask that process to send. If more than one process must open the device, set the connection's [lock](configuration.md#a-lock-across-processes).
