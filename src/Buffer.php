<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

class Buffer
{
    /**
     * Create a new buffer.
     */
    public function __construct(
        protected string $bytes = '',
    ) {
    }

    /**
     * Add bytes that have arrived to the end of the buffer.
     */
    public function append(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    /**
     * Get the bytes in the buffer.
     */
    public function contents(): string
    {
        return $this->bytes;
    }

    /**
     * Get the number of bytes in the buffer.
     */
    public function length(): int
    {
        return strlen($this->bytes);
    }

    /**
     * Get the position of the first occurrence of the needle at or after the offset.
     */
    public function indexOf(string $needle, int $offset = 0): ?int
    {
        $position = strpos($this->bytes, $needle, $offset);

        return $position === false ? null : $position;
    }

    /**
     * Get bytes from the buffer without removing them.
     */
    public function peek(int $length, int $offset = 0): string
    {
        return substr($this->bytes, $offset, $length);
    }

    /**
     * Remove the given number of bytes from the start of the buffer and return them.
     */
    public function consume(int $length): string
    {
        $bytes = substr($this->bytes, 0, $length);

        $this->bytes = substr($this->bytes, $length);

        return $bytes;
    }
}
