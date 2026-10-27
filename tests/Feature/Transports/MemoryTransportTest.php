<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature\Transports;

use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Testing\FakeTransport;
use Ipsocode\Serial\Transports\MemoryTransport;
use PHPUnit\Framework\AssertionFailedError;

class MemoryTransportTest extends UnitTestCase
{
    public function testWhatIsWrittenComesBackToBeRead(): void
    {
        $transport = new MemoryTransport;

        $this->assertFalse($transport->isOpen());
        $transport->open();
        $this->assertTrue($transport->isOpen());

        $transport->write('hello');

        $this->assertSame('hel', $transport->read(3));
        $this->assertSame('lo', $transport->read());

        $transport->write('discarded');
        $transport->flush();

        $this->assertSame('', $transport->read());

        $transport->close();
        $this->assertFalse($transport->isOpen());
    }

    public function testAFakeAnswersEachWriteWithTheNextReply(): void
    {
        $transport = new FakeTransport(['first']);
        $transport->reply(fn (string $written): string => "echo {$written}");

        $transport->write('one');
        $this->assertSame('first', $transport->read());

        $transport->write('two');
        $this->assertSame('echo two', $transport->read());

        $transport->write('three');
        $this->assertSame('', $transport->read());

        $this->assertSame(['one', 'two', 'three'], $transport->written());
        $transport->assertWritten('two');
    }

    public function testAFakeCanDeliverRepliesInPieces(): void
    {
        $transport = new FakeTransport(['abcdef'], chunkSize: 4);
        $transport->write('x');

        $this->assertSame('abcd', $transport->read());
        $this->assertSame('ef', $transport->read());
        $this->assertSame('', $transport->read(2));
    }

    public function testAssertNothingWrittenPassesUntilSomethingIsWritten(): void
    {
        $transport = new FakeTransport;
        $transport->assertNothingWritten();

        $transport->write('x');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Bytes were written to the device unexpectedly.');

        $transport->assertNothingWritten();
    }

    public function testAssertWrittenFailsForBytesThatWereNotWritten(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected bytes were not written to the device.');

        (new FakeTransport)->assertWritten('x');
    }
}
