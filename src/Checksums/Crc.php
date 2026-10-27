<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Checksums;

use InvalidArgumentException;
use Ipsocode\Serial\Contracts\Checksum;

/**
 * A cyclic redundancy check in the parameters of the CRC catalogue: width, polynomial, initial
 * value, input and output reflection, and the value the result is XORed with.
 *
 * @see https://reveng.sourceforge.io/crc-catalogue/
 */
class Crc implements Checksum
{
    /**
     * The value each byte contributes, by byte.
     *
     * @var array<int, int>
     */
    protected array $table = [];

    /**
     * The mask of the checksum's width.
     */
    protected int $mask;

    /**
     * Create a new CRC.
     *
     * @throws InvalidArgumentException when the width is outside 8 to 32 bits
     */
    public function __construct(
        public readonly int $width,
        public readonly int $polynomial,
        public readonly int $initial = 0,
        public readonly bool $reflectInput = false,
        public readonly bool $reflectOutput = false,
        public readonly int $xorOutput = 0,
    ) {
        if ($width < 8 || $width > 32) {
            throw new InvalidArgumentException("A CRC must be 8 to 32 bits wide, not {$width}.");
        }

        $this->mask = (1 << $width) - 1;

        $this->buildTable();
    }

    /**
     * Create CRC-16/IBM-3740, known as CRC-16/CCITT-FALSE.
     */
    public static function ccittFalse(): self
    {
        return new self(16, 0x1021, 0xFFFF);
    }

    /**
     * Create CRC-16/XMODEM.
     */
    public static function xmodem(): self
    {
        return new self(16, 0x1021);
    }

    /**
     * Create CRC-16/SPI-FUJITSU, known as CRC-16/AUG-CCITT.
     */
    public static function augCcitt(): self
    {
        return new self(16, 0x1021, 0x1D0F);
    }

    /**
     * Create CRC-16/MODBUS.
     */
    public static function modbus(): self
    {
        return new self(16, 0x8005, 0xFFFF, true, true);
    }

    /**
     * Create CRC-32/ISO-HDLC, the CRC-32 of zip and Ethernet.
     */
    public static function crc32(): self
    {
        return new self(32, 0x04C11DB7, 0xFFFFFFFF, true, true, 0xFFFFFFFF);
    }

    /**
     * Calculate the checksum of the given bytes.
     */
    public function calculate(string $bytes): int
    {
        $length = strlen($bytes);

        if ($this->reflectInput) {
            $crc = $this->reflect($this->initial);

            for ($i = 0; $i < $length; ++$i) {
                $crc = $this->table[($crc ^ ord($bytes[$i])) & 0xFF] ^ ($crc >> 8);
            }

            // The reflected register already holds a reflected result.
            if (! $this->reflectOutput) {
                $crc = $this->reflect($crc);
            }
        } else {
            $crc = $this->initial;
            $shift = $this->width - 8;

            for ($i = 0; $i < $length; ++$i) {
                $crc = ($this->table[(($crc >> $shift) ^ ord($bytes[$i])) & 0xFF] ^ ($crc << 8)) & $this->mask;
            }

            if ($this->reflectOutput) {
                $crc = $this->reflect($crc);
            }
        }

        return ($crc ^ $this->xorOutput) & $this->mask;
    }

    /**
     * Get the number of bytes the checksum takes on the wire.
     */
    public function size(): int
    {
        return intdiv($this->width + 7, 8);
    }

    /**
     * Build the table of the value each byte contributes.
     */
    protected function buildTable(): void
    {
        if ($this->reflectInput) {
            $polynomial = $this->reflect($this->polynomial);

            for ($byte = 0; $byte < 256; ++$byte) {
                $crc = $byte;

                for ($bit = 0; $bit < 8; ++$bit) {
                    $crc = ($crc & 1) !== 0 ? ($crc >> 1) ^ $polynomial : $crc >> 1;
                }

                $this->table[$byte] = $crc;
            }

            return;
        }

        $top = 1 << ($this->width - 1);

        for ($byte = 0; $byte < 256; ++$byte) {
            $crc = $byte << ($this->width - 8);

            for ($bit = 0; $bit < 8; ++$bit) {
                $crc = ($crc & $top) !== 0 ? (($crc << 1) ^ $this->polynomial) & $this->mask : ($crc << 1) & $this->mask;
            }

            $this->table[$byte] = $crc;
        }
    }

    /**
     * Reverse the order of the value's bits across the checksum's width.
     */
    protected function reflect(int $value): int
    {
        $reflected = 0;

        for ($bit = 0; $bit < $this->width; ++$bit) {
            $reflected = ($reflected << 1) | (($value >> $bit) & 1);
        }

        return $reflected;
    }
}
