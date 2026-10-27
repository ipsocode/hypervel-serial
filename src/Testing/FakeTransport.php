<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Testing;

use Closure;
use Ipsocode\Serial\Transports\MemoryTransport;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A device that answers each write with the next scripted reply, and records what was written.
 */
class FakeTransport extends MemoryTransport
{
    /**
     * The replies still to give, each the bytes or a callback given the bytes written.
     *
     * @var array<int, Closure(string): string|string>
     */
    protected array $replies = [];

    /**
     * The bytes written, by write.
     *
     * @var array<int, string>
     */
    protected array $written = [];

    /**
     * Create a new fake transport.
     *
     * @param array<int, Closure(string): string|string> $replies
     * @param null|positive-int $chunkSize the most bytes a read returns, to deliver replies in pieces as a device does
     */
    public function __construct(
        array $replies = [],
        protected ?int $chunkSize = null,
    ) {
        $this->replies = array_values($replies);
    }

    /**
     * Add replies to give after those already scripted.
     *
     * @param Closure(string): string|string ...$replies
     */
    public function reply(Closure|string ...$replies): static
    {
        array_push($this->replies, ...$replies);

        return $this;
    }

    /**
     * Read the bytes that have arrived, up to the given length and the chunk size.
     *
     * @param positive-int $length
     */
    public function read(int $length = 1024): string
    {
        return parent::read($this->chunkSize === null ? $length : min($length, $this->chunkSize));
    }

    /**
     * Record the bytes written, and let the next scripted reply arrive.
     */
    public function write(string $bytes): void
    {
        $this->written[] = $bytes;

        if ($this->replies !== []) {
            $reply = array_shift($this->replies);

            $this->incoming .= $reply instanceof Closure ? $reply($bytes) : $reply;
        }
    }

    /**
     * Get the bytes written, by write.
     *
     * @return array<int, string>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * Assert that the given bytes were written.
     */
    public function assertWritten(string $bytes): void
    {
        PHPUnit::assertContains($bytes, $this->written, 'The expected bytes were not written to the device.');
    }

    /**
     * Assert that nothing was written.
     */
    public function assertNothingWritten(): void
    {
        PHPUnit::assertSame([], $this->written, 'Bytes were written to the device unexpectedly.');
    }
}
