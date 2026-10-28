<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use ValueError;

/**
 * The device behind the emulated ext-dio functions in dio.php, which return what the
 * extension's own do: dio_read() null when a read yields nothing, dio_write() -1, with a warning, when it fails, and dio_tcsetattr() a ValueError for a baud rate it does not accept.
 */
class FakeDio
{
    /**
     * The baud rates ext-dio accepts on Linux.
     *
     * @var array<int, int>
     */
    public const BAUD_RATES = [50, 75, 110, 134, 150, 200, 300, 600, 1200, 1800, 2400, 4800, 9600, 19200, 38400, 57600, 115200, 230400, 460800];

    public static bool $openFails = false;

    public static ?string $device = null;

    public static ?int $flags = null;

    /**
     * @var array<string, int>
     */
    public static array $settings = [];

    public static string $incoming = '';

    public static string $written = '';

    /**
     * What each write returns, in order; once they run out, a write takes every byte.
     *
     * @var array<int, int>
     */
    public static array $writeResults = [];

    public static bool $closed = false;

    public static function reset(): void
    {
        static::$openFails = false;
        static::$device = static::$flags = null;
        static::$settings = static::$writeResults = [];
        static::$incoming = static::$written = '';
        static::$closed = false;
    }

    /**
     * @return false|resource
     */
    public static function open(string $device, int $flags): mixed
    {
        if (static::$openFails) {
            return false;
        }

        static::$device = $device;
        static::$flags = $flags;

        return fopen('php://memory', 'rb');
    }

    /**
     * @param resource $handle
     * @param array<string, int> $settings
     */
    public static function tcsetattr(mixed $handle, array $settings): bool
    {
        if (isset($settings['baud']) && ! in_array($settings['baud'], static::BAUD_RATES, true)) {
            throw new ValueError("dio_tcsetattr(): Argument #1 (\$fd) invalid baud rate {$settings['baud']}");
        }

        static::$settings = $settings;

        return true;
    }

    /**
     * @param resource $handle
     */
    public static function read(mixed $handle, int $length): ?string
    {
        if (static::$incoming === '') {
            return null;
        }

        $bytes = substr(static::$incoming, 0, $length);
        static::$incoming = substr(static::$incoming, $length);

        return $bytes;
    }

    /**
     * @param resource $handle
     */
    public static function write(mixed $handle, string $bytes): int
    {
        $result = array_shift(static::$writeResults) ?? strlen($bytes);

        if ($result < 0) {
            trigger_error('dio_write(): cannot write data to the file descriptor', E_USER_WARNING);
        }

        if ($result > 0) {
            static::$written .= substr($bytes, 0, $result);
        }

        return $result;
    }

    /**
     * @param resource $handle
     */
    public static function close(mixed $handle): void
    {
        static::$closed = true;

        fclose($handle);
    }
}
