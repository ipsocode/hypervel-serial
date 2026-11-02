<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Hypervel\Cache\ArrayStore;

/**
 * A cache store that returns the ints it holds as numeric strings, as the Redis store does
 * without a serializer.
 */
class StringifyingStore extends ArrayStore
{
    public function get(string $key): mixed
    {
        $value = parent::get($key);

        return is_int($value) ? (string) $value : $value;
    }
}
