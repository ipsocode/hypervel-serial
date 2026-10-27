# Contributing

This package is development-only until Hypervel 0.4 is released — see the
notice at the top of the [README](README.md). Changes land on `main` through
pull requests.

## Development setup

User documentation lives in `src/docs/`, which ships with the package. A new
page there gets a line in [`src/docs/index.md`](src/docs/index.md), which lists
every page in reading order and is what the README links to. Notes for coding
agents are in `docs/ai/`, which does not ship.

The suite needs PHP 8.4 or 8.5 with the Swoole and Redis extensions that
`hypervel/components` requires, the posix extension for its FIFOs, and PCOV for
the coverage gate. It needs no serial device (below). CI runs in
`ghcr.io/ipsocode/hypervel/ci-alpine:<php>-latest`, which has all of the
extensions, and is the simplest way to match it:

```sh
docker run --rm -it -v "$PWD":/app -w /app -e OTEL_SDK_DISABLED=true \
    ghcr.io/ipsocode/hypervel/ci-alpine:8.4-latest bash
composer update
```

`OTEL_SDK_DISABLED=true` matters in that image: it has no protobuf extension,
and without the variable the framework's OpenTelemetry exporters abort the run
before the first test.

No `composer.lock` is committed. Every install resolves against the current
`hypervel/components` `0.4.x-dev` on Packagist, as CI does, so a change in
`hypervel/components` that breaks this package shows up here first. Composer
downloads GitHub-hosted packages through GitHub's API; give it a GitHub token
(for example through `COMPOSER_AUTH`) if you hit the anonymous rate limit.

### Pins in `composer.json`

Three things in `composer.json` hold a version back on purpose. Each says when
it was set, why, and what lets it go.

| Pin | Since | Why | It goes when |
|---|---|---|---|
| `"phpunit/phpunit": "~13.4.3"` | the first release | The constraint `hypervel/components` keeps in its own `require-dev`, with ParaTest `^7.27.2`, its floor for PHPUnit 13.4. Components' parallel runner builds PHPUnit's internal `PhpHandler`, whose constructor changed in 13.4.0, so an untested minor release can break `--parallel`, and with it `composer test:coverage`, before any test runs; Testbench conflicts with 13.5. | `hypervel/components` lifts its own constraint: move this one to match. Composer never reads a dependency's `require-dev`, and Dependabot is told to leave PHPUnit alone, so this is done by hand. |
| `"config": {"platform": {"ext-swoole": "6.3.0RC1"}}` | the first release | `hypervel/components` `0.4.x-dev` requires `ext-swoole` `^6.3.0RC1`, and the CI image runs 6.3.0RC1. The pin makes every install resolve for that Swoole, whatever the machine running Composer has. | Swoole 6.3.0 is released and the CI image carries it: move the pin to it. |
| `"hypervel/components": "0.4.x-dev"`, with `"minimum-stability": "dev"` and `"prefer-stable": true` | the first release | Hypervel 0.4 exists only as the `0.4.x-dev` branch. | Hypervel 0.4 is released: require `^0.4` and drop both stability keys. |

## Checks

| Command | What it runs |
|---|---|
| `composer conventions` | The conventions check (below), in plain PHP: no install needed |
| `composer lint` | php-cs-fixer in dry-run mode; `composer lint:fix` applies the fixes |
| `composer analyse` | PHPStan over `src/`, at the level `phpstan.neon` sets |
| `composer test` | The suite through the Testbench CLI |
| `composer test:phpunit` | The suite through PHPUnit directly, bypassing the Testbench CLI |
| `composer test:parallel` | The suite through ParaTest |
| `composer test:coverage` | The suite under PCOV, failing below 100% line coverage |
| `composer test:purge` | Clears the Testbench skeleton's cached config, routes, views and SQLite files |

Arguments after `--` reach PHPUnit or ParaTest, e.g.
`composer test -- --filter=ConnectionTest`.

### How the suite stands in for a device

`tests/Unit` needs no application; `tests/Feature` boots one through
Testbench, and each test configures the connections it uses, so there is no
workbench.

- A connection under test talks to `Testing\FakeTransport`, which answers each
  write with a scripted reply, or to a `Testing\Simulator`.
- `StreamTransport` is tested against a FIFO opened for reading and writing,
  which loops what is written back to be read, as a tty with a device on the
  other end would. Its failure paths read and write `/proc/self/mem` at address
  0, which fails with `EIO`. `StreamTransportTest` runs with Swoole's file hook
  on, as a Hypervel application does; `StreamTransportWithoutFileHookTest` runs
  its own coroutines with it off, where a write can take only part of the bytes.
- `DioTransport` is tested against `tests/Fixtures/dio.php`, which declares
  ext-dio's functions and constants in the transport's namespace, where PHP
  finds them before the extension's. They return what the extension's own do,
  as its C source has them. The CI image carries no ext-dio, so CI never runs
  the real extension: a change to `DioTransport` is checked against a device
  by hand.

php-cs-fixer and PHPStan are configured like `hypervel/components`:
`.php-cs-fixer.dist.php` loads its rules, verbatim, from
`.github/php-cs-fixer-rules.php`, and `phpstan.neon` carries its analysis
settings and framework extensions. Some of the fixer's rules are risky — they
change behavior, not only formatting — so run `composer test` after
`composer lint:fix`, not just before committing. An inline PHPStan ignore names
its error and says why: `@phpstan-ignore <identifier> (<reason>)`.

The conventions check, `.github/scripts/conventions.php`, enforces in CI the
rules a script can decide: the `Illuminate` or `Laravel` namespace anywhere in
PHP (docblocks and strings too), container array-access,
`@codeCoverageIgnore`, a bare `@phpstan-ignore-line`, a coverage gate below
100%, and raw SQL, `eval()`, `unserialize()`, shell commands, `sleep()` or
`exit` in shipped code. It also holds the names this package writes where the
application writes too: context keys `__serial.*`, cache and lock keys
`serial:*`, commands `serial:<verb>`, publish tags `serial-*`, env vars
`SERIAL_*` and `config/serial.php`. Each violation is reported on its line.
This package's settings are `.github/conventions.php`, which records no
exceptions.

Reviews follow the maintainers' `pr-review` skill, with `review-serial`, which
says where in this package to look first; both are skills on their Claude
accounts. Ask Claude Code to review your branch with them before you push, or a
pull request by its number. The scan they run needs no skill:
`bin/review-scan.sh` flags what a diff adds or removes, `git diff
origin/main...HEAD | bash bin/review-scan.sh`. No review runs in CI.

CI (`.github/workflows/tests.yml`) runs for every pull request into `main`, each
job once the one it needs has passed: the conventions check, on the bare runner
in seconds (`.github/workflows/initial.yml`); code style, PHPStan and the suite
under the 100% coverage gate on PHP 8.4; then PHPStan and the suite on PHP 8.5.
A push to a branch without a pull request runs nothing, so open a draft pull
request to get CI early. A pull request into any other branch, such as an
umbrella issue's feature branch, runs nothing: CI runs on that branch's own pull
request into `main`, so run the checks locally before opening one. `main` is
protected: it changes only through a pull request that is up to date with it,
passes those checks, and has every review conversation resolved.

Most of `.github/` is shared by the ipsocode/hypervel-* packages and imported
from one copy: the workflows, the scripts, the php-cs-fixer rules, the issue and
pull request templates, `CODEOWNERS`, `dependabot.yml`, the release-notes config
and the security policy. So are this package's guide, the root `AGENTS.md`,
which `CLAUDE.md` imports, and the review's scan, `bin/review-scan.sh`. Each of
them but the pull request template says so in its first lines. A pull request
may still change one; the maintainer carries the change into the shared copy,
and the next import brings it to every package. `conventions.php`, the check's
settings, is this package's own. `.gitignore` leaves Claude Code's local state
in `.claude/` out of commits.

## Coroutine safety

A Hypervel worker is long-lived and serves many requests at once as coroutines,
so state that would be per-request in PHP-FPM is shared here. Every change is
held to these rules:

- A connection is one device, shared by every coroutine in the worker. What it
  holds for the device — the open transport, the sequence, the coroutine
  holding it and the reservation — changes only while the connection's mutex is
  held, in `exclusive()`. What one transaction needs, such as its buffer and
  its attempts, stays in that transaction's locals.
- No new `static` property unless it has a `flushState()`, reset from a
  `src/Testing/TestState.php` that `composer.json` names under
  `extra.hypervel.test-state`. The package holds no static state today, so it
  has neither yet.
- No native `sleep()`/`usleep()`, blocking I/O or `exit()` on a request path.
  Waits go through `Hypervel\Support\Sleep`, and a read never asks a tty for
  more bytes than have arrived (`StreamTransport::read()` says why).
- Hypervel APIs only, never the `Illuminate` namespace. Tests reach the
  container with `$app->get(...)`, never array access.
- New behavior comes with a test. `@codeCoverageIgnore` is not a way past the
  coverage gate.

## Pull requests

Fill in the pull request template and label the pull request with the type it
ticks. There is no changelog file: each release's notes are generated from the
titles of the pull requests it contains, grouped by those labels
(`.github/release.yml`), so write the title for someone reading the release. A
pull request labeled `breaking-change` makes the next release at least a minor
version.

A change a consumer will observe, such as a config key, the bytes a protocol
writes or how a transaction retries, goes in the template's *Behaviour change*
section, with the documentation update it needs (`README.md` or `src/docs/`) in
the same pull request.

## Releasing

For maintainers. A release is an annotated `vX.Y.Z` tag on `main` plus a GitHub
Release carrying the notes. Packagist reads versions off the tags, so nothing is
uploaded and `composer.json` carries no `version`: one that did not match the
tag would make Composer skip the tag.

Run the **publish** workflow on `main` (Actions → publish → *Run workflow*).
Leave the bump on `auto` — minor if a pull request merged since the previous
tag is labeled `breaking-change`, patch otherwise — or pick `patch`, `minor`
or `major`, or type an exact version. The first release has no previous tag to
bump from, so it takes an exact version. *Highlights* go at the top of the
notes; *dry run* shows the plan without tagging.

The workflow runs the full suite on PHP 8.4 and 8.5, with the coverage gate on
8.4, and only then tags the commit and publishes the Release. The notes are
generated from the pull requests merged since the previous tag. A `v*` tag
pushed by hand goes through the same suite and gets the same Release.

Composer installs a release as GitHub's archive of its tag, which leaves out
every path `.gitattributes` marks `export-ignore`: `.github/`, `AGENTS.md`,
`bin/`, `docs/`, the tests and the development configs. A new top-level file or
directory ships unless it is added there; `git archive HEAD | tar -t` lists
what would.

### Packagist

Applications install the package from Packagist, as
`ipsocode/hypervel-serial`. Packagist crawls the repository whenever GitHub
tells it about a push, through a hook on the repository, so a tag can be
installed as soon as it is pushed, the publish workflow's tags included.
Without the hook, Packagist crawls it only once a week.

Setting that up is a one-time job for a maintainer:

1. Log in to [Packagist](https://packagist.org) via GitHub, and make sure the
   Packagist application has access to the `ipsocode` organization.
2. Submit `https://github.com/ipsocode/hypervel-serial`. Packagist crawls it
   straight away, and every existing `v*` tag becomes a version.
3. Check the package list for a warning that the package is not automatically
   synced. If there is one, trigger a manual account sync, or add the webhook
   by hand: payload URL
   `https://packagist.org/api/github?username=<your Packagist username>`,
   content type `application/json`, your Packagist API token as the secret,
   and just the push event.
