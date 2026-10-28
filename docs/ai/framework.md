# Hypervel pages for each part of src/

Before changing a part of `src/`, read the sections of Hypervel 0.4's documentation listed for it
below, as page › heading. The pages are in `vendor/hypervel/components/src/docs/`; where the
hypervel-porting MCP server is set up, its `read_doc` reads a page, or one section of it.

| Part of src/ | Read |
|---|---|
| `SerialServiceProvider.php` | packages.md › Service Providers; packages.md › Default Package Configuration; packages.md › Merging Configuration Arrays; packages.md › Publishing File Groups |
| `SerialManager.php`, `Facades/` | container.md › Resolution Lifecycles; container.md › Per-Call State on Shared Instances; facades.md › How Facades Work |
| `Connection.php`: the mutex, `exclusive()` and reservations | coroutines.md › Channels; coroutines.md › Coroutine Cancellation; coroutine-context.md › Common Pitfalls; cache.md › Atomic Locks |
| `Connection.php`, `Transports/`: waits and polling | helpers.md › Sleep; mocking.md › Interacting With Time |
| `Connection.php`: events and logging | events.md › Dispatching Events; logging.md › Writing to Specific Channels |
| `Testing/` | mocking.md › Mocking Facades; testing.md › Running Tests in Coroutines |

## What the framework does not document

- Swoole's file hook, which Hypervel turns on (`SWOOLE_HOOK_ALL`, or `SWOOLE_HOOK_FLAGS` when an
  application defines it), changes how a FIFO or a tty opened with `fopen()` behaves: `fread($h, $n)`
  waits until it has all `$n` bytes, whatever the stream's blocking mode, and `stream_set_timeout()`
  does nothing. `stream_select()` with a zero timeout is the readiness check that works with the hook
  on and off; `Swoole\Coroutine\System::waitEvent()` reports a stream readable only once after a
  read, and `Swoole\Coroutine\Socket::import()` refuses a file stream. `StreamTransport` depends on
  each of these, and its two test classes run with the hook on and off.
- ext-dio's behavior, from its C source (`php/pecl-system-dio`, `src/dio.c`): `dio_read()` returns
  `null` whenever `read()` returns 0 or less; `dio_write()` returns -1, with a warning, on any
  failure, `EAGAIN` included; `dio_tcsetattr()` reads each setting as an integer, throws a
  `ValueError` for one it does not accept, turns RTS/CTS flow control on unless `flow_control` is 0,
  and always returns `true`. `tests/Fixtures/dio.php` emulates this, including the `ValueError` for a baud rate the
  extension rejects (the standard rates only) and the warning from a failed write.

## Default References

- packages.md › Package Discovery
- packages.md › Service Providers
- coroutines.md
