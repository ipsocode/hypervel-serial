<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Unit;

use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Encodings\Binary;
use Ipsocode\Serial\Encodings\HexAscii;
use Ipsocode\Serial\Exceptions\FrameException;
use PHPUnit\Framework\Attributes\DataProvider;

class EncodingsTest extends UnitTestCase
{
    public function testBinaryPassesBytesThrough(): void
    {
        $binary = new Binary;

        $this->assertSame("\x00\xFF\x02", $binary->encode("\x00\xFF\x02"));
        $this->assertSame("\x00\xFF\x02", $binary->decode("\x00\xFF\x02"));
        $this->assertSame(3, $binary->length(3));
    }

    public function testHexAsciiEncodesInUppercaseByDefault(): void
    {
        $hex = new HexAscii;

        $this->assertSame('00AB1D0F', $hex->encode("\x00\xAB\x1D\x0F"));
        $this->assertSame(8, $hex->length(4));
    }

    public function testHexAsciiCanEncodeInLowercase(): void
    {
        $this->assertSame('00ab', (new HexAscii(uppercase: false))->encode("\x00\xAB"));
    }

    public function testHexAsciiDecodesEitherCase(): void
    {
        $hex = new HexAscii;

        $this->assertSame("\x00\xAB\xCD", $hex->decode('00ABcd'));
        $this->assertSame('', $hex->decode(''));
    }

    #[DataProvider('invalidHex')]
    public function testHexAsciiRejectsBytesThatAreNotPairsOfHexDigits(string $encoded): void
    {
        $this->expectException(FrameException::class);
        $this->expectExceptionMessage('which is not pairs of hex digits');

        (new HexAscii)->decode($encoded);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHex(): array
    {
        return [
            'odd length' => ['ABC'],
            'not hex' => ['0G'],
            'control bytes' => ["\x02\x03"],
        ];
    }
}
