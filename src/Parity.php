<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

enum Parity: string
{
    case None = 'none';
    case Odd = 'odd';
    case Even = 'even';
}
