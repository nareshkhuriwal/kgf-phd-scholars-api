<?php

namespace App\Http\Controllers\Concerns;

use RuntimeException;

trait ResolvesPaperUploadDisk
{
    protected function uploadDisk(): string
    {
        $disk = (string) config('filesystems.default_upload_disk', 'azure');
        $configuredDisks = (array) config('filesystems.disks', []);

        if (!array_key_exists($disk, $configuredDisks)) {
            throw new RuntimeException("Upload disk [{$disk}] is not configured.");
        }

        return $disk;
    }

    protected function storageProviderForDisk(?string $disk): string
    {
        return (($disk ?: $this->uploadDisk()) === 'azure') ? 'azure-datalake' : 'filesystem';
    }
}
