<?php

use App\Exceptions\Backup\DatabaseDumpException;
use App\Services\Backup\Databases\FilesystemDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/fsdb_'.uniqid();
    mkdir($this->dir);
    file_put_contents($this->dir.'/a.txt', 'hello');
});

afterEach(function () {
    @unlink($this->dir.'/a.txt');
    @rmdir($this->dir);
});

test('listDatabases returns the basename of the path', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => '/var/www/site/uploads']);

    expect($db->listDatabases())->toBe(['uploads']);
});

test('dump produces a tar command scoped to the parent directory', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => $this->dir]);

    $result = $db->dump('/tmp/out.tar');

    $parent = dirname($this->dir);
    $name = basename($this->dir);

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe(
            sprintf('tar -chf %s -C %s %s', escapeshellarg('/tmp/out.tar'), escapeshellarg($parent), escapeshellarg($name))
        );
});

test('dump throws when the source path does not exist', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => '/nope/does/not/exist']);

    $db->dump('/tmp/out.tar');
})->throws(DatabaseDumpException::class);

test('restore extracts into the parent directory in place', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => '/var/www/site/uploads']);

    $result = $db->restore('/tmp/in.tar');

    expect($result->command)->toBe(
        sprintf('mkdir -p %s && tar -xf %s -C %s', escapeshellarg('/var/www/site'), escapeshellarg('/tmp/in.tar'), escapeshellarg('/var/www/site'))
    );
});

test('testConnection succeeds for an existing local path', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => $this->dir]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue();
});

test('testConnection fails for a missing path', function () {
    $db = new FilesystemDatabase;
    $db->setConfig(['filesystem_path' => '/nope/missing']);

    expect($db->testConnection()['success'])->toBeFalse();
});

test('remote (ssh) filesystem sources are rejected', function () {
    $db = new FilesystemDatabase;
    $db->setConfig([
        'filesystem_path' => '/var/www/uploads',
        'ssh_config_array' => ['host' => 'remote.example.com', 'port' => 22],
    ]);

    expect($db->testConnection()['success'])->toBeFalse();
    $db->dump('/tmp/out.tar');
})->throws(DatabaseDumpException::class);
