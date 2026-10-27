<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Ipsocode\Serial\Contracts\Command;

readonly class Request
{
    /**
     * Create a new request.
     *
     * @param string $payload the payload's bytes, before the protocol encodes them
     * @param null|int $sequence the sequence number, which the connection assigns as it sends the request
     */
    public function __construct(
        public int $code,
        public string $payload = '',
        public ?int $subCode = null,
        public ?Command $command = null,
        public ?int $sequence = null,
    ) {
    }

    /**
     * Create a request for the given command.
     *
     * @param array<string, mixed> $options
     */
    public static function for(Command $command, array $options = []): self
    {
        return new self($command->code(), $command->payload($options), $command->subCode(), $command);
    }

    /**
     * Return a copy of the request with the given sequence number.
     */
    public function withSequence(int $sequence): self
    {
        return new self($this->code, $this->payload, $this->subCode, $this->command, $sequence);
    }
}
