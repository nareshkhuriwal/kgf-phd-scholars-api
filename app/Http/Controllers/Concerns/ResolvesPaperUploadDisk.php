<?php

namespace App\Http\Controllers\Concerns;

use RuntimeException;

trait ResolvesPaperUploadDisk
{
    /**
     * Path segment under the Azure container for new library/original uploads (e.g. library/2026/03/...).
     */
    protected function libraryBlobSubdir(): string
    {
        $p = rtrim((string) config('filesystems.library_upload_prefix', 'library'), '/');

        return $p . '/' . now()->format('Y/m');
    }

    protected function uploadDisk(): string
    {
        $disk = (string) config('filesystems.default_upload_disk', 'azure');
        $configuredDisks = (array) config('filesystems.disks', []);

        if (!array_key_exists($disk, $configuredDisks)) {
            throw new RuntimeException("Upload disk [{$disk}] is not configured.");
        }

        return $disk;
    }

    /**
     * If enabled, only the upload disk is used for reads (no legacy fallback).
     * Useful once everything is confirmed migrated to Azure.
     */
    protected function strictUploadDiskOnly(): bool
    {
        // Default true: paper files are read only from the configured upload disk (Azure).
        // Set PAPERS_STRICT_UPLOAD_DISK_ONLY=false only for local legacy debugging.
        $raw = env('PAPERS_STRICT_UPLOAD_DISK_ONLY');
        if ($raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    protected function storageProviderForDisk(?string $disk): string
    {
        return (($disk ?: $this->uploadDisk()) === 'azure') ? 'azure-datalake' : 'filesystem';
    }

    /**
     * Second Azure disk when library blobs remain in AZURE_STORAGE_LEGACY_CONTAINER (e.g. "papers")
     * while AZURE_STORAGE_CONTAINER is "scholars" for new uploads and review copies.
     */
    protected function azureLegacyDisk(): ?string
    {
        $legacy = env('AZURE_STORAGE_LEGACY_CONTAINER');
        $main = (string) config('filesystems.disks.azure.container', 'scholars');

        if (! is_string($legacy) || $legacy === '' || $legacy === $main) {
            return null;
        }

        return 'azure_legacy';
    }
}
