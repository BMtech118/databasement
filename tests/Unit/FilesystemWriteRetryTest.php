<?php

use App\Services\Backup\Filesystems\FilesystemProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

// Wasabi refused multipart parts with 503s and failed nightly backups
// (6 Nov 2026). A refused write is retried from the start of the file.
function flakyFilesystem(int $failures): Filesystem
{
    $adapter = new class($failures) extends LocalFilesystemAdapter
    {
        public function __construct(public int $failures)
        {
            parent::__construct(sys_get_temp_dir().'/bk-'.uniqid());
        }

        public function writeStream(string $path, $contents, \League\Flysystem\Config $config): void
        {
            if ($this->failures-- > 0) {
                fread($contents, 3); // a partial read, as a failed multipart upload leaves it
                throw UnableToWriteFile::atLocation($path, 'ServiceUnavailable');
            }
            parent::writeStream($path, $contents, $config);
        }
    };

    return new Filesystem($adapter);
}

function writeWithRetry(Filesystem $fs, string $source, string $dest): void
{
    $provider = new FilesystemProvider([]);
    $method = new ReflectionMethod($provider, 'writeToFilesystem');
    $method->invoke($provider, $fs, $source, $dest);
}

beforeEach(fn () => FilesystemProvider::$writeRetrySeconds = 0);
afterEach(fn () => FilesystemProvider::$writeRetrySeconds = 30);

test('a write refused twice succeeds on the third attempt with the whole file', function () {
    $src = tempnam(sys_get_temp_dir(), 'bk');
    file_put_contents($src, 'backup-contents');
    $fs = flakyFilesystem(2);

    writeWithRetry($fs, $src, 'out.gz');

    expect($fs->read('out.gz'))->toBe('backup-contents');
});

test('a write refused every time still fails the backup', function () {
    $src = tempnam(sys_get_temp_dir(), 'bk');
    file_put_contents($src, 'x');

    writeWithRetry(flakyFilesystem(3), $src, 'out.gz');
})->throws(UnableToWriteFile::class);
