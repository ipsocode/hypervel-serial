<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Unit;

use Hypervel\Testing\UnitTestCase;
use Ipsocode\Serial\Buffer;
use Ipsocode\Serial\Exceptions\TimeoutException;
use Ipsocode\Serial\Frame;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Response;
use Ipsocode\Serial\RetryPolicy;
use Ipsocode\Serial\Sequences\Counter;
use Ipsocode\Serial\Tests\Fixtures\StatusResult;
use Ipsocode\Serial\Tests\Fixtures\TestCommand;

class ValuesTest extends UnitTestCase
{
    public function testBufferAppendsPeeksAndConsumes(): void
    {
        $buffer = new Buffer('ab');
        $buffer->append('cdef');

        $this->assertSame('abcdef', $buffer->contents());
        $this->assertSame(6, $buffer->length());
        $this->assertSame(2, $buffer->indexOf('c'));
        $this->assertSame(4, $buffer->indexOf('e', 3));
        $this->assertNull($buffer->indexOf('a', 1));
        $this->assertSame('cd', $buffer->peek(2, 2));

        $this->assertSame('abc', $buffer->consume(3));
        $this->assertSame('def', $buffer->contents());
        $this->assertSame('def', $buffer->consume(10));
        $this->assertSame(0, $buffer->length());
    }

    public function testRequestForACommandTakesItsCodesAndPayload(): void
    {
        $request = Request::for(TestCommand::Echo, ['data' => 'hello']);

        $this->assertSame(0x0A, $request->code);
        $this->assertSame(2, $request->subCode);
        $this->assertSame('hello', $request->payload);
        $this->assertSame(TestCommand::Echo, $request->command);
        $this->assertNull($request->sequence);
    }

    public function testRequestWithSequenceIsACopy(): void
    {
        $request = new Request(5, 'payload', 3);
        $numbered = $request->withSequence(9);

        $this->assertNull($request->sequence);
        $this->assertSame(9, $numbered->sequence);
        $this->assertSame([5, 'payload', 3, null], [$numbered->code, $numbered->payload, $numbered->subCode, $numbered->command]);
    }

    public function testResponseIsASuccessCarryingThePayload(): void
    {
        $response = new Response(new Frame('raw', 'payload', ['length' => 7]));

        $this->assertTrue($response->success());
        $this->assertFalse($response->error());
        $this->assertNull($response->status());
        $this->assertSame('payload', $response->data());
        $this->assertSame('raw', $response->frame->raw);
    }

    public function testCounterNumbersRequestsAndStartsOverAfterItsMaximum(): void
    {
        $counter = new Counter(start: 1, max: 3);
        $request = new Request(1);

        $this->assertSame([1, 2, 3, 1, 2], array_map(fn (): int => $counter->next($request), range(1, 5)));

        $counter->reset();

        $this->assertSame(1, $counter->next($request));
    }

    public function testCounterDefaultsToSixteenBits(): void
    {
        $counter = new Counter;

        $this->assertSame(0, $counter->next(new Request(1)));
    }

    public function testRetryPolicyRetriesFailuresWhileAttemptsRemain(): void
    {
        $policy = new RetryPolicy(times: 2, delay: 0.5);
        $failure = new TimeoutException;

        $this->assertTrue($policy->shouldRetry(1, $failure));
        $this->assertTrue($policy->shouldRetry(2, new StatusResult(-1)));
        $this->assertTrue($policy->shouldRetry(2, new StatusResult(1)));
        $this->assertFalse($policy->shouldRetry(3, $failure));
        $this->assertFalse($policy->shouldRetry(1, new StatusResult(0)));
    }

    public function testRetryPolicyWithTimesKeepsTheDelay(): void
    {
        $policy = (new RetryPolicy(times: 2, delay: 0.5))->withTimes(0);

        $this->assertSame(0, $policy->times);
        $this->assertSame(0.5, $policy->delay);
        $this->assertFalse($policy->shouldRetry(1, new TimeoutException));
    }
}
