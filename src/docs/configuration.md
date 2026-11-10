# Configuration

- [Connections](#connections)
- [Transports](#transports)
    - [The stream transport](#the-stream-transport)
    - [The dio transport](#the-dio-transport)
    - [The memory transport](#the-memory-transport)
    - [Custom transports](#custom-transports)
- [Timing and retries](#timing-and-retries)
- [Optional settings](#optional-settings)
    - [Sequence numbers](#sequence-numbers)
    - [A lock across processes](#a-lock-across-processes)
    - [Logging](#logging)
    - [Simulating the device](#simulating-the-device)

<a name="connections"></a>
## Connections

Each connection in `config/serial.php` is one device. `Serial::connection()` returns the `default` connection, and `Serial::connection('name')` any other:

```php
'default' => env('SERIAL_CONNECTION', 'default'),

'connections' => [
    'default' => [
        'transport' => env('SERIAL_TRANSPORT', 'stream'),
        'device' => env('SERIAL_DEVICE', '/dev/ttyUSB0'),
        'baud' => (int) env('SERIAL_BAUD', 115200),
        'data_bits' => 8,
        'stop_bits' => 1,
        'parity' => 'none',
        'flow_control' => false,
        'protocol' => FramedProtocol::class,
        'sequence' => Counter::class,
        'timeout' => (float) env('SERIAL_TIMEOUT', 2.0),
        'retries' => (int) env('SERIAL_RETRIES', 2),
        'retry_delay' => 0.1,
        'poll_interval' => 0.01,
        'lock' => null,
        'log' => env('SERIAL_LOG_CHANNEL'),
        'simulator' => null,
    ],
],
```

Connections you publish are merged with the package's by name, so a connection of your own sits beside `default` rather than replacing it. The `protocol` is a class the container resolves, so it may take dependencies in its constructor.

A connection of your own does not inherit the keys of `default`, so each connection sets every key its transport reads, apart from the [optional settings](#optional-settings). A key that is missing, or holds a value of the wrong type, fails with an exception that names it, such as `serial.connections.scale.retries`. The `timeout`, `retry_delay` and `poll_interval` settings take an integer or a float.

<a name="transports"></a>
## Transports

The `transport` opens the device and moves its bytes.

<a name="the-stream-transport"></a>
### The stream transport

The `stream` transport opens `device` as a PHP stream, without blocking. PHP cannot set a terminal's line settings, so the transport leaves `baud`, `data_bits`, `stop_bits`, `parity` and `flow_control` alone, and you set them before the application starts, with `stty`:

```sh
stty -F /dev/ttyUSB0 115200 cs8 -cstopb -parenb -crtscts raw -echo clocal
```

`raw` and `-echo` pass every byte through untouched, which a framed protocol needs, and `clocal` keeps the port from waiting for a modem's carrier signal. A systemd unit can run the command as an `ExecStartPre`, and a container's entrypoint before it starts the server; in Docker, pass the device with `devices: ["/dev/ttyUSB0:/dev/ttyUSB0"]`.

Under Swoole's file hook, which Hypervel turns on, a read of a terminal waits until it has every byte it asked for. The transport asks for one byte at a time, and only once one has arrived, so it never waits on the device. At serial speeds the extra calls cost nothing you would notice.

<a name="the-dio-transport"></a>
### The dio transport

The `dio` transport opens the device through the [dio](https://pecl.php.net/package/dio) extension, which sets the line settings itself from `baud`, `data_bits`, `stop_bits`, `parity` (`none`, `odd` or `even`) and `flow_control` (RTS/CTS), in raw mode. The extension is not part of PHP, so install it before you choose this transport. The transport recognizes a write that would block, or was interrupted, by the English text of the system's error message, because the extension reports only a warning and no error number. PHP leaves the message locale at `C` unless the application changes it, so this holds by default; if the application calls `setlocale()` with a non-English `LC_ALL` or `LC_MESSAGES`, those writes fail with a `TransportException` instead of being tried again.

The extension accepts only the standard baud rates, 50 to 460800 on Linux (such as 9600, 19200, 38400, 57600, 115200, 230400 and 460800). Any other rate, such as 250000, 921600 or 1000000, makes every transaction throw a `ValueError`, which is a configuration error and is not retried. Use the `stream` transport for a rate the extension does not accept.

A device that is unplugged while the connection is open makes the next read or write throw a `TransportException` at once, instead of looking like a device that has nothing to send. The connection then closes the device and reopens it on the next attempt. A read detects this when the device's node has been removed, as it is when a USB adapter is unplugged; a device that stays present but hangs up is not detected. A device that is merely quiet still reads as an empty string.

Opening and closing the device are plain system calls that cannot yield to the other coroutines, so a hung device can stall the whole worker while they run:

- **Closing.** With `flow_control` on, a device that is powered off or holds CTS low keeps the frames the connection queued from leaving, and closing the port waits for them, up to the port's closing wait, 30 seconds by default, once for each page of queued output. The transport turns flow control off just before it closes the device, so the queued frames leave at line speed instead of waiting for CTS; the device receives them if it comes back.
- **Opening.** A USB adapter, such as a CP210x or FTDI, whose firmware hangs while it stays plugged in blocks the open for about 5 seconds for each of the two control transfers it makes, and again on every retry. The transport cannot shorten this.

Where a hung device is likely, use the `stream` transport, which opens the device without blocking.

<a name="the-memory-transport"></a>
### The memory transport

The `memory` transport is a loopback: every byte written comes back to be read. A protocol whose requests are also valid replies can be tried against it without a device.

<a name="custom-transports"></a>
### Custom transports

A transport implements `Ipsocode\Serial\Contracts\Transport`. Register one by name with the manager's `extend` method, from a service provider's `boot` method; the callback receives the application and the connection's configuration:

```php
use Ipsocode\Serial\Facades\Serial;

Serial::extend('tcp', function ($app, array $config) {
    return new TcpTransport($config['host'], $config['port']);
});
```

Then set the connection's `transport` to `tcp`.

<a name="timing-and-retries"></a>
## Timing and retries

A reply has `timeout` seconds to arrive. An attempt that gets no valid reply, or a reply whose result is not a success, is tried again up to `retries` more times, `retry_delay` seconds apart, so a connection makes at most `retries + 1` attempts. While a reply arrives, the connection reads the device every `poll_interval` seconds. A transaction can [set its own](transactions.md#timeouts-and-retries) timeout and retries.

<a name="optional-settings"></a>
## Optional settings

These settings may be left out of a connection; each says what leaving it out means.

<a name="sequence-numbers"></a>
### Sequence numbers

The connection numbers each request before the protocol encodes it, using the class `sequence` names, and the protocol writes the number into the frame if its header has one. `Ipsocode\Serial\Sequences\Counter`, the default, counts up from 0 and starts over after 65535. A device with its own rules, such as one that expects a continuing sequence only within a transfer, gets a class of its own that implements `Ipsocode\Serial\Contracts\Sequence`. Each connection makes its own instance, except that a connection with a [lock](#a-lock-across-processes) keeps the default `Counter`'s number in the lock's cache store, so every process sharing the device continues one sequence. A sequence class of your own keeps whatever state it keeps, so it must share its number across processes itself.

<a name="a-lock-across-processes"></a>
### A lock across processes

Transactions on a connection never interleave within one process. When more than one process opens the same device, set `lock` so each hold of the device also takes a cache lock named `serial:<connection>`:

```php
'lock' => ['store' => 'redis', 'seconds' => 10, 'wait' => 5],
```

`store` is the cache store, the default store when it is `null`; `seconds` is how long the lock lasts; `wait` is how long to wait for another process to let go before failing with a `LockTimeoutException`. The store's locks must support [refreshing](https://hypervel.org/docs/cache#refreshing-locks), as those of the `redis`, `database`, `file`, `swoole` and `array` stores do; the connection fails when it is resolved otherwise. The default `Counter` also keeps its number in this store, under `serial:<connection>:sequence`, so the processes continue one sequence and the [sequence check](protocols.md#turning-replies-into-results) rejects a late reply to another process's request. It costs two more store round trips per transaction. Without `lock`, nothing is locked across processes.

A hold can last longer than `seconds`, such as a callback that sends many blocks or a transaction that tries again. Before each attempt, once half of `seconds` has passed since the lock was taken or last refreshed, the connection refreshes the lock, so set `seconds` to at least twice the longest single attempt: the `timeout` plus the time to write the request. If the lock has expired or another process has taken it, the transaction throws an `Ipsocode\Serial\Exceptions\LockLostException` before it writes anything, is not tried again, and is reported like any failed transaction. A hold that ends without its lock, such as a callback that never sends again, logs an error on the connection's [log channel](#logging).

<a name="logging"></a>
### Logging

`log` names a log channel that records each frame sent and received at the `debug` level, each attempt that is tried again as a `warning`, and each transaction that fails as an `error`. Without it, nothing is logged.

<a name="simulating-the-device"></a>
### Simulating the device

`simulator` names a class that extends `Ipsocode\Serial\Testing\Simulator`, which then answers in place of the device: the transport is never opened, so you can develop without the hardware. Each connection makes its own instance. [Testing](testing.md#simulating-a-device) shows how to write one.
