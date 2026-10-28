<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature\Transports;

use Closure;
use Hypervel\Support\Sleep;
use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Exceptions\TransportException;
use Ipsocode\Serial\Parity;
use Ipsocode\Serial\Tests\Fixtures\FakeDio;
use Ipsocode\Serial\Transports\DioTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use ValueError;

require_once __DIR__ . '/../../Fixtures/dio.php';

class DioTransportTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeDio::reset();
    }

    protected function tearDown(): void
    {
        FakeDio::reset();

        parent::tearDown();
    }

    #[DataProvider('parities')]
    public function testOpenSetsTheLineSettingsInRawMode(Parity $parity, int $dioParity): void
    {
        $transport = $this->transport($parity);

        $transport->open();

        $this->assertTrue($transport->isOpen());
        $this->assertSame('/dev/serial0', FakeDio::$device);
        $this->assertSame(0x2 | 0x100 | 0x800, FakeDio::$flags);
        $this->assertSame([
            'baud' => 115200,
            'bits' => 8,
            'stop' => 1,
            'parity' => $dioParity,
            'flow_control' => 0,
            'is_canonical' => 0,
        ], FakeDio::$settings);
    }

    /**
     * @return array<string, array{Parity, int}>
     */
    public static function parities(): array
    {
        return [
            'none' => [Parity::None, 0],
            'odd' => [Parity::Odd, 1],
            'even' => [Parity::Even, 2],
        ];
    }

    public function testFlowControlIsPassedAsAnInteger(): void
    {
        (new DioTransport('/dev/serial0', 9600, 7, 2, Parity::None, true, 1.0, 0.01))->open();

        $this->assertSame(['baud' => 9600, 'bits' => 7, 'stop' => 2, 'parity' => 0, 'flow_control' => 1, 'is_canonical' => 0], FakeDio::$settings);
    }

    public function testOpeningADeviceThatCannotBeOpenedFails(): void
    {
        FakeDio::$openFails = true;

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to open the serial device [/dev/serial0].');

        $this->transport()->open();
    }

    public function testReadReturnsWhatHasArrivedAndNothingWhenNothingHas(): void
    {
        $transport = $this->transport();
        $transport->open();
        FakeDio::$incoming = 'hello';

        $this->assertSame('hel', $transport->read(3));
        $this->assertSame('lo', $transport->read());
        $this->assertSame('', $transport->read());
    }

    public function testWriteKeepsWritingUntilTheDeviceTakesEveryByte(): void
    {
        Sleep::fake();

        $transport = $this->transport();
        $transport->open();
        FakeDio::$writeResults = [2, -1, 0];

        $transport->write('hello');

        $this->assertSame('hello', FakeDio::$written);
        Sleep::assertSleptTimes(3);
    }

    public function testAWriteTheDeviceDoesNotTakeInTimeFails(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $transport = $this->transport();
        $transport->open();
        FakeDio::$writeResults = array_fill(0, 1000, -1);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The serial device [/dev/serial0] did not take the bytes written to it within 1 seconds.');

        $transport->write('hello');
    }

    public function testAWriteToAnUnpluggedDeviceFailsAtOnce(): void
    {
        $transport = $this->transport();
        $transport->open();
        FakeDio::$unplugged = true;

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to write to the serial device [/dev/serial0]: dio_write(): cannot write data to the file descriptor 5, Input/output error');

        $transport->write('hello');
    }

    public function testAReadFromAnUnpluggedDeviceFailsAtOnce(): void
    {
        $transport = $this->transport();
        $transport->open();
        FakeDio::$unplugged = true;

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The serial device [/dev/serial0] is no longer available.');

        $transport->read();
    }

    public function testFlushDiscardsTheBytesThatHaveArrived(): void
    {
        $transport = $this->transport();
        $transport->open();
        FakeDio::$incoming = str_repeat('x', 3000);

        $transport->flush();

        $this->assertSame('', FakeDio::$incoming);
    }

    public function testCloseClosesTheDeviceOnce(): void
    {
        $transport = $this->transport();
        $transport->close();
        $this->assertFalse(FakeDio::$closed);

        $transport->open();
        $transport->close();

        $this->assertTrue(FakeDio::$closed);
        $this->assertFalse($transport->isOpen());
    }

    /**
     * @param Closure(DioTransport): mixed $operation
     */
    #[DataProvider('operationsOnAClosedDevice')]
    public function testUsingADeviceThatIsNotOpenFails(Closure $operation): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The serial device [/dev/serial0] is not open.');

        $operation($this->transport());
    }

    /**
     * @return array<string, array{Closure(DioTransport): mixed}>
     */
    public static function operationsOnAClosedDevice(): array
    {
        return [
            'read' => [fn (DioTransport $transport): mixed => $transport->read()],
            'write' => [fn (DioTransport $transport): mixed => $transport->write('x')],
            'flush' => [fn (DioTransport $transport): mixed => $transport->flush()],
        ];
    }

    public function testABaudRateTheExtensionDoesNotAcceptFails(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('invalid baud rate 921600');

        (new DioTransport('/dev/serial0', 921600, 8, 1, Parity::None, false, 1.0, 0.01))->open();
    }

    protected function transport(Parity $parity = Parity::None): DioTransport
    {
        return new DioTransport('/dev/serial0', 115200, 8, 1, $parity, false, 1.0, 0.01);
    }
}
