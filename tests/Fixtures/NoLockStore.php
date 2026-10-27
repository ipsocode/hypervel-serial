<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Hypervel\Cache\ArrayStore;
use Hypervel\Contracts\Cache\Store;

/**
 * A cache store that cannot hold locks.
 */
class NoLockStore implements Store
{
    protected ArrayStore $store;

    public function __construct()
    {
        $this->store = new ArrayStore;
    }

    public function get(string $key): mixed
    {
        return $this->store->get($key);
    }

    public function many(array $keys): array
    {
        return $this->store->many($keys);
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->store->put($key, $value, $seconds);
    }

    public function putMany(array $values, int $seconds): bool
    {
        return $this->store->putMany($values, $seconds);
    }

    public function increment(string $key, int $value = 1): bool|int
    {
        return $this->store->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->store->decrement($key, $value);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($key, $value);
    }

    public function touch(string $key, int $seconds): bool
    {
        return $this->store->touch($key, $seconds);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }
}
