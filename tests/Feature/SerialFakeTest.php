<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Sleep;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Exceptions\ReservedException;
use Ipsocode\Serial\Facades\Serial;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use Ipsocode\Serial\SerialManager;
use Ipsocode\Serial\Testing\SerialFake;
use Ipsocode\Serial\Tests\Fixtures\AuxiliaryCommand;
use Ipsocode\Serial\Tests\Fixtures\CountingSimulator;
use Ipsocode\Serial\Tests\Fixtures\HeaderProtocol;
use Ipsocode\Serial\Tests\Fixtures\StatusResult;
use Ipsocode\Serial\Tests\Fixtures\TestCommand;
use Ipsocode\Serial\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class SerialFakeTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get('config');

        $config->set('serial.connections.default.protocol', HeaderProtocol::class);
        $config->set('serial.connections.default.retry_delay', 0.0);
        $config->set('serial.connections.default.lock', ['store' => 'missing', 'seconds' => 10, 'wait' => 0]);
        $config->set('serial.connections.auxiliary', $config->get('serial.connections.default'));
    }

    public function testFakeReplacesTheManager(): void
    {
        $fake = Serial::fake();

        $this->assertInstanceOf(SerialFake::class, $fake);
        $this->assertInstanceOf(SerialManager::class, $fake);
        $this->assertSame($fake, Serial::getFacadeRoot());
        $this->assertSame($fake, $this->app->make('serial'));
    }

    public function testWithoutAResponderEveryRequestSucceedsWithAnEmptyReply(): void
    {
        Serial::fake();

        $result = TestCommand::Ping->send();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->success());
        $this->assertSame('', $result->data());
    }

    public function testACommandSendsItsCodesAndPayload(): void
    {
        Serial::fake();

        TestCommand::Echo->send(['data' => 'hello']);

        $request = Serial::sent()[0];

        $this->assertSame(0x0A, $request->code);
        $this->assertSame(2, $request->subCode);
        $this->assertSame('hello', $request->payload);
        $this->assertSame(TestCommand::Echo, $request->command);
        $this->assertSame(0, $request->sequence);
    }

    public function testASimulatorAnswersEachRequest(): void
    {
        $simulator = new CountingSimulator(busyFor: 1);
        Serial::fake($simulator);

        $result = TestCommand::Echo->send(['data' => 'hello']);

        $this->assertSame('hello', $result->data());
        $this->assertCount(2, $simulator->requests);
        Serial::assertSent(TestCommand::Echo, 2);
    }

    public function testACallbackAnswersEachRequestAndIsToldTheConnection(): void
    {
        Serial::fake(fn (Request $request, string $connection): Result => new StatusResult(0, $connection));

        $this->assertSame('default', TestCommand::Ping->send()->data());
        $this->assertSame('auxiliary', AuxiliaryCommand::Reset->send()->data());
    }

    public function testRetriesAndTimeoutAreForwarded(): void
    {
        Sleep::fake();
        Serial::fake(fn (): Result => new StatusResult(1));

        $result = TestCommand::Ping->send(retries: 0, timeout: 0.5);

        $this->assertFalse($result->success());
        Serial::assertSentCount(1);
    }

    public function testAReservationAppliesToFakedConnections(): void
    {
        Serial::fake();
        Serial::reserve('calibration');

        try {
            TestCommand::Ping->send();
            $this->fail('The command went through a reservation.');
        } catch (ReservedException) {
        }

        TestCommand::Ping->send(owner: 'calibration');

        Serial::assertSentCount(1);
    }

    public function testSentFiltersByCommandOrCallback(): void
    {
        Serial::fake();

        TestCommand::Ping->send();
        TestCommand::Echo->send(['data' => 'one']);
        AuxiliaryCommand::Reset->send();

        $this->assertCount(3, Serial::sent());
        $this->assertCount(1, Serial::sent(TestCommand::Ping));
        $this->assertSame(['one'], array_map(
            fn (Request $request): string => $request->payload,
            Serial::sent(fn (Request $request, string $connection): bool => $connection === 'default' && $request->payload !== ''),
        ));
        $this->assertSame([AuxiliaryCommand::Reset], array_map(
            fn (Request $request) => $request->command,
            Serial::sent(fn (Request $request, string $connection): bool => $connection === 'auxiliary'),
        ));
    }

    public function testTheAssertionsPass(): void
    {
        Serial::fake();

        Serial::assertNothingSent();

        TestCommand::Ping->send();
        TestCommand::Ping->send();

        Serial::assertSent(TestCommand::Ping);
        Serial::assertSent(TestCommand::Ping, 2);
        Serial::assertSent(fn (Request $request): bool => $request->subCode === 1);
        Serial::assertNotSent(TestCommand::Echo);
        Serial::assertSentCount(2);
    }

    public function testAssertSentFailsWhenNothingMatches(): void
    {
        Serial::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected serial request was not sent.');

        Serial::assertSent(TestCommand::Ping);
    }

    public function testAssertSentFailsWhenTheCountDiffers(): void
    {
        Serial::fake();
        TestCommand::Ping->send();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected serial request was sent 1 times instead of 2 times.');

        Serial::assertSent(TestCommand::Ping, 2);
    }

    public function testAssertNotSentFailsWhenARequestMatches(): void
    {
        Serial::fake();
        TestCommand::Ping->send();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An unexpected serial request was sent.');

        Serial::assertNotSent(TestCommand::Ping);
    }

    public function testAssertNothingSentFailsWhenARequestWasSent(): void
    {
        Serial::fake();
        TestCommand::Ping->send();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Serial requests were sent unexpectedly.');

        Serial::assertNothingSent();
    }

    public function testAssertSentCountFailsWhenTheCountDiffers(): void
    {
        Serial::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The number of serial requests sent does not match.');

        Serial::assertSentCount(1);
    }
}
