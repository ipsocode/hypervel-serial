<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Concerns\InteractsWithSerial;
use Ipsocode\Serial\Contracts\Command;

enum TestCommand: int implements Command
{
    use InteractsWithSerial;

    case Ping = 1;
    case Echo = 2;

    public function code(): int
    {
        return 0x0A;
    }

    public function subCode(): ?int
    {
        return $this->value;
    }

    public function payload(array $options = []): string
    {
        return $options['data'] ?? '';
    }
}
