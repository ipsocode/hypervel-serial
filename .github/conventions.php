<?php

declare(strict_types=1);

/*
 * This package's settings for .github/scripts/conventions.php, the conventions check every
 * ipsocode/hypervel-* package imports. initial.yml runs it ahead of the test jobs, and
 * `composer conventions` locally. The script's header lists the rules.
 */

return [
    // The name this package writes under wherever the application writes too:
    // __serial.* context keys, serial:* commands and store keys, serial-* publish
    // tags, SERIAL_* env vars and config/serial.php.
    'slug' => 'serial',

    // Paths under the package root that no rule governs.
    'excluded' => [],

    // Namespaces banned on top of Illuminate\ and Laravel\, each with what to use
    // instead.
    'banned_namespaces' => [],

    // Function-name prefixes banned in shipped code, each with what to use instead.
    'banned_functions' => [],

    // Paths phpunit.xml's <source> may leave out of the coverage gate.
    'coverage_excludes' => [],

    // The exceptions, per rule: '<path>' => [<exact number of hits>, '<why>']. The
    // check fails as soon as a count stops matching, either way.
    'allowed' => [],
];
