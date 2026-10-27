<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Contracts;

interface Command
{
    /**
     * Get the code the device knows the command by.
     */
    public function code(): int;

    /**
     * Get the sub-command code, or null when the protocol has none.
     */
    public function subCode(): ?int;

    /**
     * Build the payload sent with the command.
     *
     * @param array<string, mixed> $options
     */
    public function payload(array $options = []): string;
}
