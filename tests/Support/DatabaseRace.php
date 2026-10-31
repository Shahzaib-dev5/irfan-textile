<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

trait DatabaseRace
{
    protected function race(array $tasks): array
    {
        $directory = storage_path('framework/testing/inventory-'.Str::uuid());
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $barrier = $directory.'/go';
        $processes = [];
        $files = [];
        $config = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_COLLATION' => 'utf8mb4_unicode_ci', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr'];
        try {
            foreach ($tasks as $index => $task) {
                $input = $directory.'/'.$index.'.json';
                $output = $directory.'/'.$index.'.out';
                file_put_contents($input, json_encode($task + ['actor' => $this->admin->id, 'worker' => $index], JSON_THROW_ON_ERROR));
                $files[] = [$input, $output, $barrier.'.ready.'.$index];
                $process = new Process([PHP_BINARY, base_path('tests/Support/inventory_worker.php'), $input, $barrier, $output], base_path(), $environment, null, 90);
                $process->start();
                $processes[] = $process;
            }
            // Allow slower Windows/OneDrive startup; both workers must still reach the barrier.
            $deadline = microtime(true) + 45;
            do {
                $ready = count(array_filter($files, fn ($paths) => is_file($paths[2]))) === count($tasks);
                if (! $ready) {
                    usleep(10000);
                }
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready, 'Both workers must be ready before the shared start barrier opens.');
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode(file_get_contents($files[$index][1]), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($files as $paths) {
                foreach ($paths as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            rmdir($directory);
        }
    }
}
