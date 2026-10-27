# hypervel-serial

Serial ports for [Hypervel](https://github.com/hypervel/components): named
connections to a device over a tty, framed protocols with configurable
encodings and checksums, transactions that never interleave between
coroutines and retry what fails, and a simulator for working without the
hardware.

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.**
>
> It is built for Hypervel 0.4, which has no release yet: 0.4 exists only as the
> `0.4.x-dev` branch of [`hypervel/components`](https://github.com/hypervel/components),
> and this package is developed and tested against that moving branch. Until 0.4
> ships, anything here can change without a deprecation period — the API and the
> configuration included. Use it to evaluate or to build against Hypervel 0.4,
> and pin the version you tested.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/components` itself rather than its split packages.
- A serial device the application can open, such as `/dev/ttyUSB0`, with its
  line settings applied by `stty` ([why](src/docs/configuration.md#the-stream-transport)),
  or ext-dio to have the package apply them.

## Installation

```sh
composer require ipsocode/hypervel-serial
php artisan vendor:publish --tag=serial-config
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`.

## Usage

```php
use Ipsocode\Serial\Facades\Serial;
use Ipsocode\Serial\Request;

$result = Serial::connection()->transact(new Request(code: 0x04));

if ($result->success()) {
    $firmware = $result->data();
}
```

[Getting started](src/docs/getting-started.md) shows the same with a device's
commands as an enum, and a protocol of its own.

## Documentation

[The documentation](src/docs/index.md) lists every page in reading order.

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report security issues privately, as described in
[SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## License

MIT. See [LICENSE](LICENSE).
