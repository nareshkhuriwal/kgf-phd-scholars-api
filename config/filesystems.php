<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | In production on cPanel, set FILESYSTEM_DISK=uploads in .env so files
    | are saved directly under public/uploads (no symlink needed).
    |
    */

    'default' => env('FILESYSTEM_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // Private, non-web-accessible storage
        'local' => [
            'driver'  => 'local',
            'root'    => storage_path('app/private'),
            'serve'   => true,
            'throw'   => false,
            'report'  => false,
        ],

        // Standard Laravel public disk (needs storage:link → often blocked on cPanel)
        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL') . '/uploads',
            'visibility' => 'public',
            'throw'      => false,
            'report'     => false,
        ],

        // web-served uploads WITHOUT symlink (recommended for cPanel)
        'uploads' => [
            'driver'     => 'local',
            'root'       => public_path('uploads'),
            'url'        => env('APP_URL') . '/uploads',
            'visibility' => 'public',
            'throw'      => false,
            'report'     => false,
        ],

        // (Optional) S3
        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION'),
            'bucket'                  => env('AWS_BUCKET'),
            'url'                     => env('AWS_URL'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
            'report'                  => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Only needed if you keep using the "public" disk. With "uploads" you
    | don't need any symlinks.
    |
    */

    'links' => [
        public_path('uploads') => storage_path('app/public'),
    ],

];
