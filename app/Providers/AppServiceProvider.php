<?php

namespace App\Providers;

use App\Filesystem\AzureStorageBlobFilesystemAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::globalOptions([
            'timeout' => (int) config('http_client.timeout', 25),
            'connect_timeout' => (int) config('http_client.connect_timeout', 8),
        ]);

        // Override package driver: Guzzle used for Azure must not default to "wait forever" (hits PHP max_execution_time).
        Storage::extend('azure-storage-blob', function (Application $app, array $config) {
            return new AzureStorageBlobFilesystemAdapter($config);
        });
    }
}
