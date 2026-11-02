<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests\Fixtures;

use Hypervel\Cache\CacheLock;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;

/**
 * A cache store whose locks cannot be refreshed, as with a driver that has no atomic refresh.
 */
class CacheLockStore extends NoLockStore implements LockProvider
{
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new CacheLock($this, $name, $seconds, $owner);
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }
}
