<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\DatabaseDumpException;
use App\Exceptions\Backup\RestoreException;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\DTO\DatabaseOperationLog;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\SftpFilesystem;

/**
 * Backs up an arbitrary file or directory by archiving it with tar.
 *
 * Unlike the database engines, there is nothing to "dump" — the source of truth
 * is the files themselves (uploads, .env, non-git config). Each configured path
 * becomes one snapshot; dump() produces an uncompressed tar and the backup
 * pipeline applies the volume/global compression on top, exactly like the plain
 * SQL engines. Restore extracts the archive back into place.
 *
 * The agent runs the tar/extract command on its own host, so a Filesystem server
 * must be backed by an agent that is LOCAL to the machine holding the paths.
 * Remote (SSH) filesystem sources are intentionally unsupported — deploy a local
 * agent on the target host instead of trying to stream a whole tree over SFTP.
 */
class FilesystemDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly SftpFilesystem $sftpFilesystem = new SftpFilesystem,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function listDatabases(): array
    {
        return [basename((string) ($this->config['filesystem_path'] ?? ''))];
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $this->assertLocal();

        $sourcePath = rtrim((string) ($this->config['filesystem_path'] ?? ''), '/');

        if ($sourcePath === '') {
            throw new DatabaseDumpException('Filesystem backup path is empty.');
        }

        if (! file_exists($sourcePath)) {
            throw new DatabaseDumpException("Filesystem backup path does not exist: {$sourcePath}");
        }

        $parent = dirname($sourcePath);
        $name = basename($sourcePath);

        // -C changes into the parent so the archive stores a relative path
        // (portable, no absolute leading slash), then archives the file/dir by name.
        // -h follows symlinks so linked assets are captured, not stored as dangling links.
        return new DatabaseOperationResult(
            command: sprintf(
                'tar -chf %s -C %s %s',
                escapeshellarg($outputPath),
                escapeshellarg($parent),
                escapeshellarg($name),
            ),
            log: new DatabaseOperationLog(
                'Archived filesystem path',
                'success',
                ['path' => $sourcePath],
            ),
        );
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $this->assertLocal();

        $targetPath = rtrim((string) ($this->config['filesystem_path'] ?? ''), '/');

        if ($targetPath === '') {
            throw new RestoreException('Filesystem restore target path is empty.');
        }

        // The archive stores paths relative to the original parent, so extracting
        // into the parent restores the file/dir in place (overwriting existing).
        $parent = dirname($targetPath);

        return new DatabaseOperationResult(
            command: sprintf(
                'mkdir -p %s && tar -xf %s -C %s',
                escapeshellarg($parent),
                escapeshellarg($inputPath),
                escapeshellarg($parent),
            ),
            log: new DatabaseOperationLog(
                'Extracted filesystem archive',
                'success',
                ['path' => $targetPath],
            ),
        );
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        // Filesystem restores extract over the existing path; no preparation needed.
    }

    public function testConnection(): array
    {
        if ($this->isRemote()) {
            return [
                'success' => false,
                'message' => 'Remote (SSH) filesystem sources are not supported. Attach a local agent running on the host that holds these paths.',
                'details' => [],
            ];
        }

        $paths = $this->config['filesystem_paths'] ?? [];

        if (empty($paths) && ! empty($this->config['filesystem_path'])) {
            $paths = [$this->config['filesystem_path']];
        }

        if (empty($paths)) {
            return ['success' => false, 'message' => 'A file or folder path is required.', 'details' => []];
        }

        $failures = [];
        $entries = [];

        foreach ($paths as $path) {
            $path = (string) $path;

            if ($path === '' || ! file_exists($path)) {
                $failures[] = 'Path does not exist: '.$path;

                continue;
            }

            if (! is_readable($path)) {
                $failures[] = 'Path is not readable: '.$path;

                continue;
            }

            $entries[] = [
                'path' => $path,
                'type' => is_dir($path) ? 'directory' : 'file',
            ];
        }

        if (! empty($failures)) {
            return ['success' => false, 'message' => implode("\n", $failures), 'details' => []];
        }

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => ['output' => json_encode($entries, JSON_PRETTY_PRINT)],
        ];
    }

    /**
     * Guard the dump/restore path: these commands run on the agent host and
     * operate on local paths, so an SSH config means the source is unreachable.
     */
    private function assertLocal(): void
    {
        if ($this->isRemote()) {
            throw new DatabaseDumpException(
                'Remote (SSH) filesystem sources are not supported. Run a local agent on the host that holds these paths.'
            );
        }
    }

    private function isRemote(): bool
    {
        return ($this->config['ssh_config'] ?? null) instanceof DatabaseServerSshConfig
            || is_array($this->config['ssh_config_array'] ?? null);
    }
}
