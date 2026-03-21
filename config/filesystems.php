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

    'default' => env('FILESYSTEM_DISK', 'azure'),

    /*
    |--------------------------------------------------------------------------
    | Paper Upload Disk (Library / Papers)
    |--------------------------------------------------------------------------
    |
    | Keep all paper file uploads on Azure Data Lake-compatible storage by
    | default. You can override with PAPERS_UPLOAD_DISK if required.
    |
    */
    'default_upload_disk' => env('PAPERS_UPLOAD_DISK', env('AZURE_STORAGE_DISK', 'azure')),

    /*
    |--------------------------------------------------------------------------
    | Scholars container layout (blob virtual folders)
    |--------------------------------------------------------------------------
    |
    | Container: AZURE_STORAGE_CONTAINER (e.g. "scholars")
    |   - Original / library PDFs: {library_upload_prefix}/Y/m/...   (default prefix: "library")
    |   - Review working copies:   {review_working_copy_prefix}/{paper_id}/r{n}/... (default: "reviews")
    |
    */
    'library_upload_prefix' => env('LIBRARY_BLOB_PREFIX', 'library'),

    /*
    |--------------------------------------------------------------------------
    | Review working copy blob prefix (within the upload disk container)
    |--------------------------------------------------------------------------
    */
    'review_working_copy_prefix' => env('REVIEW_WORKING_COPY_PREFIX', 'reviews'),

    /*
    |--------------------------------------------------------------------------
    | Optional second Azure container (library blobs still in "papers", new in "scholars")
    |--------------------------------------------------------------------------
    |
    | Set AZURE_STORAGE_LEGACY_CONTAINER when existing paper_files paths live in another
    | container than AZURE_STORAGE_CONTAINER (e.g. legacy "papers", primary "scholars").
    |
    */
    'azure_legacy_container' => env('AZURE_STORAGE_LEGACY_CONTAINER'),

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
        'azure' => [
            'driver' => 'azure-storage-blob',
            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
            // Container name (e.g. "scholars"). Inside it: library/... originals, review/... working copies.
            'container' => env('AZURE_STORAGE_CONTAINER', 'scholars'),
        ],

        'azure_legacy' => [
            'driver' => 'azure-storage-blob',
            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
            'container' => env('AZURE_STORAGE_LEGACY_CONTAINER') ?: env('AZURE_STORAGE_CONTAINER', 'scholars'),
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
