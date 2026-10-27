<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Ipsocode\Serial\Concerns\InteractsWithSerial;
use Ipsocode\Serial\Contracts\Command;

enum AuxiliaryCommand: int implements Command
{
    use InteractsWithSerial;

    case Reset = 7;

    public function code(): int
    {
        return $this->value;
    }

    public function subCode(): ?int
    {
        return null;
    }

    public function payload(array $options = []): string
    {
        return '';
    }

    public function serialConnection(): ?string
    {
        return 'auxiliary';
    }
}
