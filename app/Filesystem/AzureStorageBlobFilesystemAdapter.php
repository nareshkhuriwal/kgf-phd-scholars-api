<?php

declare(strict_types=1);

namespace App\Filesystem;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\Models\BlobServiceClientOptions;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use AzureOss\Storage\Common\Auth\ClientSecretCredential;
use AzureOss\Storage\Common\Middleware\HttpClientOptions;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use Psr\Http\Message\UriInterface;

/**
 * Same as azure-oss/storage-blob-laravel adapter, but passes Guzzle timeout/connect_timeout
 * so blob I/O does not hang until PHP max_execution_time (StreamHandler).
 *
 * @property AzureBlobStorageAdapter $adapter
 */
final class AzureStorageBlobFilesystemAdapter extends FilesystemAdapter
{
    public bool $canProvideTemporaryUrls;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $serviceClient = self::createBlobServiceClient($config);
        $containerClient = $serviceClient->getContainerClient($config['container']);
        $this->canProvideTemporaryUrls = $containerClient->canGenerateSasUri();
        $isPublicContainer = $config['is_public_container'] ?? false;
        $adapter = new AzureBlobStorageAdapter(
            $containerClient,
            $config['prefix'] ?? $config['root'] ?? '',
            isPublicContainer: $isPublicContainer,
        );

        parent::__construct(
            new Filesystem($adapter, $config),
            $adapter,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function httpClientOptionsFromConfig(array $config): HttpClientOptions
    {
        $timeout = isset($config['timeout']) ? (int) $config['timeout'] : (int) config('filesystems.azure_http.timeout', 120);
        $connect = isset($config['connect_timeout']) ? (int) $config['connect_timeout'] : (int) config('filesystems.azure_http.connect_timeout', 15);
        if ($timeout < 1) {
            $timeout = 120;
        }
        if ($connect < 1) {
            $connect = 15;
        }

        $verify = $config['verify'] ?? null;

        return new HttpClientOptions(
            timeout: $timeout,
            connectTimeout: $connect,
            verifySsl: is_bool($verify) ? $verify : null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function blobServiceClientOptions(array $config): BlobServiceClientOptions
    {
        return new BlobServiceClientOptions(self::httpClientOptionsFromConfig($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createBlobServiceClient(array $config): BlobServiceClient
    {
        $options = self::blobServiceClientOptions($config);

        $connectionString = $config['connection_string'] ?? null;
        if (is_string($connectionString) && $connectionString !== '') {
            return BlobServiceClient::fromConnectionString($connectionString, $options);
        }

        $tenantId = $config['tenant_id'] ?? null;
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;

        if (! is_string($tenantId) || ! is_string($clientId) || ! is_string($clientSecret)) {
            throw new \InvalidArgumentException('Token-based credentials require [tenant_id], [client_id], and [client_secret].');
        }

        $uri = self::buildBlobEndpointUri($config);
        $credential = new ClientSecretCredential($tenantId, $clientId, $clientSecret);

        return new BlobServiceClient($uri, $credential, $options);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function buildBlobEndpointUri(array $config): UriInterface
    {
        $endpoint = $config['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            return new Uri(rtrim($endpoint, '/').'/');
        }

        $accountName = $config['account_name'] ?? null;
        if (! is_string($accountName) || $accountName === '') {
            throw new \InvalidArgumentException('Either [endpoint] or [account_name] must be provided for token-based credentials.');
        }

        $endpointSuffix = $config['endpoint_suffix'] ?? 'core.windows.net';
        $endpoint = sprintf('https://%s.blob.%s', $accountName, $endpointSuffix);

        return new Uri($endpoint.'/');
    }

    public function url($path)
    {
        return $this->adapter->publicUrl($path, new Config);
    }

    public function providesTemporaryUrls()
    {
        return $this->canProvideTemporaryUrls;
    }

    /** @phpstan-ignore-next-line */
    public function temporaryUrl($path, $expiration, array $options = [])
    {
        return $this->adapter->temporaryUrl(
            $path,
            $expiration,
            new Config(array_merge(['permissions' => 'r'], $options)),
        );
    }

    /** @phpstan-ignore-next-line */
    public function temporaryUploadUrl($path, $expiration, array $options = [])
    {
        $url = $this->adapter->temporaryUrl(
            $path,
            $expiration,
            new Config(array_merge(['permissions' => 'cw'], $options)),
        );
        $contentType = isset($options['content-type']) && is_string($options['content-type'])
            ? $options['content-type']
            : 'application/octet-stream';

        return [
            'url' => $url,
            'headers' => [
                'x-ms-blob-type' => 'BlockBlob',
                'Content-Type' => $contentType,
            ],
        ];
    }
}
