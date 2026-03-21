<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Http facade defaults (Guzzle)
    |--------------------------------------------------------------------------
    |
    | Applied globally via AppServiceProvider so outbound HTTP does not block
    | until PHP max_execution_time.
    |
    */
    'timeout' => (int) env('HTTP_CLIENT_TIMEOUT', 25),
    'connect_timeout' => (int) env('HTTP_CLIENT_CONNECT_TIMEOUT', 8),

];
