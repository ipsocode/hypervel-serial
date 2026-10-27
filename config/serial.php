<?php

declare(strict_types=1);

use Ipsocode\Serial\Protocols\FramedProtocol;
use Ipsocode\Serial\Sequences\Counter;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Serial Connection
    |--------------------------------------------------------------------------
    |
    | The connection Serial::connection() returns when it is given no name,
    | and the one a command's send() uses unless the command names another.
    |
    */

    'default' => env('SERIAL_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Serial Connections
    |--------------------------------------------------------------------------
    |
    | Each connection is one device. The transport opens it: "stream" opens a
    | tty whose line settings are set before the application starts, with
    | stty; "dio" opens it through ext-dio and sets the line settings itself
    | from baud, data_bits, stop_bits, parity and flow_control; "memory" is a
    | loopback. The protocol, a class the container resolves, frames each
    | request and reads the device's reply.
    |
    | A reply has "timeout" seconds to arrive, and an attempt that gets no
    | valid reply, or a reply that is not a success, is tried again up to
    | "retries" more times, "retry_delay" seconds apart. "poll_interval" is
    | the seconds between reads while a reply arrives.
    |
    | Optional settings: "sequence" names the class that numbers requests
    | (Counter when it is missing). "lock" takes a cross-process cache lock
    | for each hold of the device, as ['store' => null, 'seconds' => 10,
    | 'wait' => 5]: the cache store, how long the lock lasts and how long to
    | wait for it. "log" names the log channel that records each frame.
    | "simulator" names a Testing\Simulator that answers in place of the
    | device, which the transport then never opens.
    |
    */

    'connections' => [
        'default' => [
            'transport' => env('SERIAL_TRANSPORT', 'stream'),
            'device' => env('SERIAL_DEVICE', '/dev/ttyUSB0'),
            'baud' => (int) env('SERIAL_BAUD', 115200),
            'data_bits' => 8,
            'stop_bits' => 1,
            'parity' => 'none',
            'flow_control' => false,
            'protocol' => FramedProtocol::class,
            'sequence' => Counter::class,
            'timeout' => (float) env('SERIAL_TIMEOUT', 2.0),
            'retries' => (int) env('SERIAL_RETRIES', 2),
            'retry_delay' => 0.1,
            'poll_interval' => 0.01,
            'lock' => null,
            'log' => env('SERIAL_LOG_CHANNEL'),
            'simulator' => null,
        ],
    ],
];
