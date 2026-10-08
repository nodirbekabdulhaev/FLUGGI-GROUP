<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pure-PHP database dump/restore (works on shared hosting without mysqldump / exec()).
 * Dumps are gzip-compressed .sql files stored outside the public directory.
 */
class BackupService
{
    private const SEP = "\n;--EOS\n";

    public function dir(): string
    {
        $d = storage_path('app/backups');
        File::ensureDirectoryExists($d);

        return $d;
    }

    /** @return array<int,array{name:string,size:int,time:int}> newest first */
    public function list(): array
    {
        return collect(File::files($this->dir()))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql.gz'))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => $f->getMTime()])
            ->sortByDesc('time')->values()->all();
    }

    public function path(string $name): string
    {
        // whitelist: no traversal
        abort_unless(preg_match('/^[A-Za-z0-9_\-]+\.sql\.gz$/', $name), 404);
        $p = $this->dir().'/'.$name;
        abort_unless(is_file($p), 404);

        return $p;
    }

    public function run(?int $keep = null): string
    {
        $keep ??= (int) config('backup.keep', 14);
        $name = 'backup_'.now()->format('Y-m-d_His').'.sql.gz';
        $path = $this->dir().'/'.$name;

        $gz = gzopen($path, 'wb9');
        try {
            foreach ($this->statements() as $stmt) {
                gzwrite($gz, $stmt.self::SEP);
            }
        } finally {
            gzclose($gz);
        }

        // retention
        foreach (array_slice($this->list(), max(1, $keep)) as $old) {
            File::delete($this->dir().'/'.$old['name']);
        }
        Log::channel('cron')->info('backup created', ['file' => $name, 'size' => filesize($path)]);

        return $name;
    }

    /** @return \Generator<string> */
    protected function statements(): \Generator
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            yield 'SET FOREIGN_KEY_CHECKS=0';
            yield "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'";
            $tables = array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'));
        } else {
            yield 'PRAGMA foreign_keys=OFF';
            $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');
        }

        foreach ($tables as $table) {
            $q = DB::getQueryGrammar()->wrapTable($table);
            yield "DROP TABLE IF EXISTS $q";
            yield $driver === 'mysql'
                ? array_values((array) DB::select("SHOW CREATE TABLE $q")[0])[1]
                : DB::selectOne("SELECT sql FROM sqlite_master WHERE name = ?", [$table])->sql;

            // keyset pagination on id (fast on millions of rows); pivot tables without id fall back to offset paging
            $rows = Schema::hasColumn($table, 'id') ? DB::table($table)->lazyById(1000) : DB::table($table)->orderBy(DB::raw('1'))->lazy(1000);
            $buf = [];
            $cols = null;
            foreach ($rows as $row) {
                $row = (array) $row;
                $cols ??= implode(',', array_map(fn ($c) => DB::getQueryGrammar()->wrap($c), array_keys($row)));
                $buf[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)).')';
                if (count($buf) >= 200) {
                    yield "INSERT INTO $q ($cols) VALUES ".implode(',', $buf);
                    $buf = [];
                }
            }
            if ($buf) {
                yield "INSERT INTO $q ($cols) VALUES ".implode(',', $buf);
            }
        }

        yield $driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON';
    }

    /** Safety net: dump the current state before a restore overwrites it. */
    public function backupBeforeRestore(): string
    {
        return $this->run(max((int) config('backup.keep', 14), 2) + 1);
    }

    public function restore(string $name): int
    {
        $sql = gzdecode(file_get_contents($this->path($name)));
        abort_if($sql === false, 422, 'Файл повреждён');

        $n = 0;
        foreach (explode(self::SEP, $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                DB::unprepared($stmt);
                $n++;
            }
        }

        return $n;
    }
}
