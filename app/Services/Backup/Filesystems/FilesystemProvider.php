<?php

namespace App\Services\Backup\Filesystems;

use App\Enums\VolumeType;
use App\Exceptions\Backup\FilesystemException;
use App\Models\SnapshotFile;
use App\Models\Volume;
use App\Services\Backup\DTO\VolumeConfig;
use League\Flysystem\Filesystem;

class FilesystemProvider
{
    private const WRITE_ATTEMPTS = 3;

    /** Seconds before the first retry (doubles each time); tests set 0. */
    public static int $writeRetrySeconds = 30;

    /** @var FilesystemInterface[] */
    private array $filesystems = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function add(FilesystemInterface $filesystem): void
    {
        $this->filesystems[] = $filesystem;
    }

    /**
     * Get a filesystem instance for a Volume (uses database config)
     */
    public function getForVolume(Volume $volume): Filesystem
    {
        return $this->getForVolumeConfig(VolumeConfig::fromVolume($volume));
    }

    /**
     * Get a filesystem instance by config name (uses config/backup.php)
     *
     * @deprecated Use getForVolume() when you have a Volume object
     */
    public function get(string $name): Filesystem
    {
        $type = $this->getConfig($name, 'type');

        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($type)) {
                return $filesystem->get($this->config[$name] ?? []);
            }
        }

        throw new FilesystemException("The requested filesystem type {$type} is not currently supported.");
    }

    public function getConfig(string $name, ?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config[$name] ?? null;
        }

        return $this->config[$name][$key] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->config);
    }

    public function transfer(Volume $volume, string $source, string $destination): void
    {
        $filesystem = $this->getForVolume($volume);
        $this->ensureRootExists($volume->type, $filesystem);
        $this->writeToFilesystem($filesystem, $source, $destination);
    }

    public function download(SnapshotFile $file, string $destination): void
    {
        $filesystem = $this->getForVolume($file->volume);
        $this->readFromFilesystem($filesystem, $file->storedFilename(), $destination);
    }

    /**
     * Get a filesystem instance for a VolumeConfig DTO (config already decrypted).
     */
    public function getForVolumeConfig(VolumeConfig $config): Filesystem
    {
        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($config->type)) {
                return $filesystem->get($config->config);
            }
        }

        throw new FilesystemException("The requested filesystem type {$config->type} is not currently supported.");
    }

    public function transferFromConfig(VolumeConfig $config, string $source, string $destination): void
    {
        $filesystem = $this->getForVolumeConfig($config);
        $this->ensureRootExists($config->type, $filesystem);
        $this->writeToFilesystem($filesystem, $source, $destination);
    }

    public function downloadFromConfig(VolumeConfig $config, string $remoteFilename, string $destination): void
    {
        $filesystem = $this->getForVolumeConfig($config);
        $this->readFromFilesystem($filesystem, $remoteFilename, $destination);
    }

    /**
     * SFTP volumes may not have the root directory yet.
     * FTP adapters resolve the root on connect and throw if missing — nothing we can do here.
     * Local and S3 don't need this — local roots are auto-created and S3 has virtual directories.
     */
    private function ensureRootExists(string $type, Filesystem $filesystem): void
    {
        if (VolumeType::tryFrom($type) === VolumeType::SFTP) {
            $filesystem->createDirectory('.');
        }
    }

    private function writeToFilesystem(Filesystem $filesystem, string $source, string $destination): void
    {
        $stream = fopen($source, 'r');
        if ($stream === false) {
            throw new FilesystemException("Failed to open file: {$source}");
        }

        try {
            // A remote store can refuse a write for a moment (Wasabi 503s on
            // multipart parts). Retry the whole upload from the start of the
            // file before failing the backup.
            $attempt = 0;
            while (true) {
                try {
                    $filesystem->writeStream($destination, $stream);
                    break;
                } catch (\League\Flysystem\UnableToWriteFile $e) {
                    if (++$attempt >= self::WRITE_ATTEMPTS || ! rewind($stream)) {
                        throw $e;
                    }
                    sleep(self::$writeRetrySeconds * $attempt);
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function readFromFilesystem(Filesystem $filesystem, string $remoteFilename, string $destination): void
    {
        $localStream = fopen($destination, 'w');

        if ($localStream === false) {
            throw new FilesystemException("Failed to open destination file: {$destination}");
        }

        try {
            $stream = $filesystem->readStream($remoteFilename);
            $bytes = stream_copy_to_stream($stream, $localStream);
            if ($bytes === false) {
                throw new FilesystemException("Failed to copy stream for: {$remoteFilename}");
            }
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
            fclose($localStream);
        }
    }
}
