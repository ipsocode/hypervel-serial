<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Unit;

use Closure;
use Hypervel\Testing\UnitTestCase;
use InvalidArgumentException;
use Ipsocode\Serial\Checksums\Crc;
use Ipsocode\Serial\Checksums\Lrc;
use Ipsocode\Serial\Checksums\Sum8;
use Ipsocode\Serial\Checksums\XorSum;
use PHPUnit\Framework\Attributes\DataProvider;

class ChecksumsTest extends UnitTestCase
{
    /**
     * @param Closure(): Crc $make
     */
    #[DataProvider('presets')]
    public function testCrcMatchesTheCatalogueCheckValue(Closure $make, int $check, int $size): void
    {
        $crc = $make();

        $this->assertSame($check, $crc->calculate('123456789'));
        $this->assertSame($size, $crc->size());
    }

    /**
     * The CRC catalogue's check value of each preset: its CRC of "123456789". Each CRC is made
     * in the test, where coverage is measured, not in the provider.
     *
     * @return array<string, array{Closure(): Crc, int, int}>
     */
    public static function presets(): array
    {
        return [
            'CRC-16/CCITT-FALSE' => [fn (): Crc => Crc::ccittFalse(), 0x29B1, 2],
            'CRC-16/XMODEM' => [fn (): Crc => Crc::xmodem(), 0x31C3, 2],
            'CRC-16/AUG-CCITT' => [fn (): Crc => Crc::augCcitt(), 0xE5CC, 2],
            'CRC-16/MODBUS' => [fn (): Crc => Crc::modbus(), 0x4B37, 2],
            'CRC-32' => [fn (): Crc => Crc::crc32(), 0xCBF43926, 4],
            'CRC-8/SMBUS' => [fn (): Crc => new Crc(8, 0x07), 0xF4, 1],
            'CRC-24/OPENPGP' => [fn (): Crc => new Crc(24, 0x864CFB, 0xB704CE), 0x21CF02, 3],
        ];
    }

    public function testCrc32MatchesPhp(): void
    {
        $bytes = random_bytes(257);

        $this->assertSame(crc32($bytes), Crc::crc32()->calculate($bytes));
    }

    public function testCrcOfNothingIsTheInitialValueTransformed(): void
    {
        $this->assertSame(0xFFFF, Crc::ccittFalse()->calculate(''));
        $this->assertSame(0, Crc::crc32()->calculate(''));
    }

    #[DataProvider('reflections')]
    public function testCrcMatchesABitwiseReferenceForEachReflection(bool $reflectInput, bool $reflectOutput): void
    {
        $bytes = random_bytes(64);

        foreach ([[16, 0x1021, 0x1D0F, 0x0000], [32, 0x04C11DB7, 0xFFFFFFFF, 0xFFFFFFFF], [12, 0x80F, 0x123, 0x0]] as [$width, $polynomial, $initial, $xorOutput]) {
            $crc = new Crc($width, $polynomial, $initial, $reflectInput, $reflectOutput, $xorOutput);

            $this->assertSame(
                $this->reference($bytes, $width, $polynomial, $initial, $reflectInput, $reflectOutput, $xorOutput),
                $crc->calculate($bytes),
                "Width {$width}",
            );
        }
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function reflections(): array
    {
        return [
            'neither' => [false, false],
            'input only' => [true, false],
            'output only' => [false, true],
            'both' => [true, true],
        ];
    }

    public function testCrcRejectsAWidthOutsideItsRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A CRC must be 8 to 32 bits wide, not 7.');

        new Crc(7, 0x09);
    }

    public function testCrcRejectsAWidthAbove32(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Crc(33, 0x1);
    }

    public function testLrcIsTheTwosComplementOfTheSum(): void
    {
        // Modbus ASCII "read one holding register at 0 from unit 1": 01 03 00 00 00 01.
        $this->assertSame(0xFB, (new Lrc)->calculate("\x01\x03\x00\x00\x00\x01"));
        $this->assertSame(0, (new Lrc)->calculate(''));
        $this->assertSame(1, (new Lrc)->size());
    }

    public function testXorSumMatchesAnNmeaSentence(): void
    {
        $this->assertSame(0x47, (new XorSum)->calculate('GPGGA,123519,4807.038,N,01131.000,E,1,08,0.9,545.4,M,46.9,M,,'));
        $this->assertSame(1, (new XorSum)->size());
    }

    public function testSum8IsTheSumModulo256(): void
    {
        $this->assertSame(0xDD, (new Sum8)->calculate('123456789'));
        $this->assertSame(1, (new Sum8)->size());
    }

    /**
     * Calculate a CRC one bit at a time, as the Rocksoft model defines it.
     */
    private function reference(string $bytes, int $width, int $polynomial, int $initial, bool $reflectInput, bool $reflectOutput, int $xorOutput): int
    {
        $mask = (1 << $width) - 1;
        $top = 1 << ($width - 1);
        $crc = $initial;

        foreach (str_split($bytes) as $character) {
            $byte = ord($character);

            if ($reflectInput) {
                $byte = $this->reflectBits($byte, 8);
            }

            for ($bit = 7; $bit >= 0; --$bit) {
                $feedback = (($crc & $top) !== 0) !== ((($byte >> $bit) & 1) === 1);
                $crc = ($crc << 1) & $mask;

                if ($feedback) {
                    $crc ^= $polynomial;
                }
            }
        }

        if ($reflectOutput) {
            $crc = $this->reflectBits($crc, $width);
        }

        return ($crc ^ $xorOutput) & $mask;
    }

    private function reflectBits(int $value, int $width): int
    {
        $reflected = 0;

        for ($bit = 0; $bit < $width; ++$bit) {
            $reflected = ($reflected << 1) | (($value >> $bit) & 1);
        }

        return $reflected;
    }
}
