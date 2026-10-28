<?php

declare(strict_types=1);

/*
 * ext-dio's functions and constants, declared in the namespace where DioTransport calls them
 * unqualified, so PHP finds these before the extension's global ones. The CI image does not
 * carry ext-dio, so DioTransport's tests run against these, which answer from FakeDio.
 */

namespace Ipsocode\Serial\Transports;

use Ipsocode\Serial\Tests\Fixtures\FakeDio;

const O_RDWR = 0x2;
const O_NOCTTY = 0x100;
const O_NONBLOCK = 0x800;

/**
 * @return false|resource
 */
function dio_open(string $filename, int $flags, int $mode = 0): mixed
{
    return FakeDio::open($filename, $flags);
}

/**
 * @param resource $fd
 * @param array<string, int> $args
 */
function dio_tcsetattr(mixed $fd, array $args): bool
{
    return FakeDio::tcsetattr($fd, $args);
}

/**
 * @param resource $fd
 */
function dio_read(mixed $fd, int $n = 1024): ?string
{
    return FakeDio::read($fd, $n);
}

/**
 * @param resource $fd
 *
 * @return array{nlink: int}
 */
function dio_stat(mixed $fd): array
{
    return FakeDio::stat($fd);
}

/**
 * @param resource $fd
 */
function dio_write(mixed $fd, string $data, int $len = 0): int
{
    return FakeDio::write($fd, $data);
}

/**
 * @param resource $fd
 */
function dio_close(mixed $fd): void
{
    FakeDio::close($fd);
}
