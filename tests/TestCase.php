<?php

declare(strict_types=1);

namespace Ipsocode\Serial\Tests;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Ipsocode\Serial\Facades\Serial;
use Ipsocode\Serial\SerialServiceProvider;

/**
 * Base test case for tests that need the application: it registers the package as package
 * discovery would in a consuming application.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SerialServiceProvider::class];
    }

    /**
     * Get the package aliases.
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return ['Serial' => Serial::class];
    }
}
