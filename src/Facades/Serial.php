<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Facades;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Facade;
use Ipsocode\Serial\Contracts\Result;
use Ipsocode\Serial\Request;
use Ipsocode\Serial\Testing\SerialFake;
use Ipsocode\Serial\Testing\Simulator;

/**
 * @method static \Ipsocode\Serial\Connection connection(?string $name = null)
 * @method static string getDefaultInstance()
 * @method static void setDefaultInstance(string $name)
 * @method static array<string, mixed> getInstanceConfig(string $name)
 * @method static void disconnect(?string $name = null)
 * @method static void purge(?string $name = null)
 * @method static \Ipsocode\Serial\SerialManager forgetInstance(array<int, string>|string|null $name = null)
 * @method static \Ipsocode\Serial\SerialManager extend(string $name, \Closure $callback)
 * @method static \Ipsocode\Serial\Contracts\Result transact(\Ipsocode\Serial\Request $request, ?float $timeout = null, ?int $retries = null, ?string $owner = null)
 * @method static mixed exclusive(\Closure $callback)
 * @method static bool reserve(string $owner, ?float $seconds = null)
 * @method static bool release(string $owner)
 * @method static null|string reservedBy()
 * @method static void assertSent(\Closure|\Ipsocode\Serial\Contracts\Command $request, ?int $times = null)
 * @method static void assertNotSent(\Closure|\Ipsocode\Serial\Contracts\Command $request)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static array<int, \Ipsocode\Serial\Request> sent(\Closure|\Ipsocode\Serial\Contracts\Command|null $request = null)
 *
 * @see \Ipsocode\Serial\SerialManager
 * @see \Ipsocode\Serial\Testing\SerialFake
 */
class Serial extends Facade
{
    /**
     * Replace the manager with a fake whose connections send to the given simulator or callback.
     *
     * Tests only. The fake stays in place for the rest of the test.
     *
     * @param null|(Closure(Request, string): Result)|Simulator $responder
     */
    public static function fake(Closure|Simulator|null $responder = null): SerialFake
    {
        /** @var Application $app */
        $app = static::getFacadeApplication();

        static::swap($fake = new SerialFake($app, $responder));

        return $fake;
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'serial';
    }
}
