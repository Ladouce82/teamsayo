<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/** Nightly compressed MySQL dump kept for 14 days (in addition to the hosting provider's backups). */
class Backup extends Command
{
    protected $signature = 'teamsayo:backup';
    protected $description = 'Write a compressed database backup to storage/app/backups and keep the last 14.';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql' && config('database.default') !== 'mariadb') {
            $this->warn('Backups are only configured for MySQL/MariaDB.');

            return self::SUCCESS;
        }
        $db = config('database.connections.' . config('database.default'));
        $dir = storage_path('app/backups');
        @mkdir($dir, 0700, true);
        $file = $dir . '/teamsayo-' . now()->format('Y-m-d-His') . '.sql.gz';
        $cnf = tempnam(sys_get_temp_dir(), 'tsdb');
        file_put_contents($cnf, "[client]\nuser=\"{$db['username']}\"\npassword=\"{$db['password']}\"\nhost=\"{$db['host']}\"\nport=\"{$db['port']}\"\n");
        chmod($cnf, 0600);
        $p = Process::fromShellCommandline('mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --no-tablespaces ' . escapeshellarg($db['database']) . ' | gzip > ' . escapeshellarg($file));
        $p->setTimeout(900)->run();
        unlink($cnf);
        if (! $p->isSuccessful() || filesize($file) < 100) {
            @unlink($file);
            $this->error('Backup failed: ' . trim($p->getErrorOutput()));

            return self::FAILURE;
        }
        chmod($file, 0600);
        $files = glob($dir . '/teamsayo-*.sql.gz');
        rsort($files);
        foreach (array_slice($files, 14) as $old) {
            unlink($old);
        }
        $this->info('Backup written: ' . basename($file));

        return self::SUCCESS;
    }
}
