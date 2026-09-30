<?php

namespace App\Services\Tenancy\DataPurge;

use Generator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Streams a purge's deleted rows to a private, gzipped NDJSON file and reads
 * them back for a restore.
 *
 * File layout, one JSON object per line, in this order:
 *   header    {type, version, tenant_id, created_at, order: [tables…], columns: {table: [cols…]}}
 *   rows      {type, table, rows: [{col: value…}…]}          (parents first, ascending id)
 *   nullify   {type, table, column, loose, rows: {id: old}}   rows that were only unlinked
 *   reconcile {type, changes: [{kind, table, id, column, …}…]}   state repaired after deleting
 *   footer    {type, counts: {table: n}}
 */
final class PurgeBackup
{
    public const VERSION = 1;

    /** Start a new line-group once this many bytes of rows are buffered. */
    private const CHUNK_BYTES = 1_000_000;

    private const CHUNK_ROWS = 200;

    /** @var resource|null */
    private $handle = null;

    private ?string $relativePath = null;

    public static function relativePathFor(int $tenantId, string $uuid): string
    {
        return config('tenancy-purge.backup_directory')."/{$tenantId}/{$uuid}.ndjson.gz";
    }

    public static function absolutePath(string $relativePath): string
    {
        return self::disk()->path($relativePath);
    }

    public static function exists(string $relativePath): bool
    {
        return self::disk()->exists($relativePath);
    }

    public static function delete(string $relativePath): void
    {
        self::disk()->delete($relativePath);
    }

    public static function checksum(string $relativePath): string
    {
        return hash_file('sha256', self::absolutePath($relativePath));
    }

    public function open(string $relativePath): void
    {
        self::disk()->makeDirectory(dirname($relativePath));

        $handle = gzopen(self::absolutePath($relativePath), 'wb6');

        if ($handle === false) {
            throw new PurgeAbortedException('The backup file could not be created, so nothing was deleted.');
        }

        $this->handle = $handle;
        $this->relativePath = $relativePath;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new PurgeAbortedException('A record could not be encoded into the backup ('.$e->getMessage().'), so nothing was deleted.');
        }

        if (gzwrite($this->handle, $line."\n") === false) {
            throw new PurgeAbortedException('The backup file could not be written (disk full?), so nothing was deleted.');
        }
    }

    /**
     * Writes rows in bounded groups so a single line never grows unmanageable.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function writeRows(string $table, iterable $rows): void
    {
        $buffer = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $buffer[] = $row;
            $bytes += strlen((string) json_encode($row, JSON_PARTIAL_OUTPUT_ON_ERROR));

            if (count($buffer) >= self::CHUNK_ROWS || $bytes >= self::CHUNK_BYTES) {
                $this->write(['type' => 'rows', 'table' => $table, 'rows' => $buffer]);
                $buffer = [];
                $bytes = 0;
            }
        }

        if ($buffer !== []) {
            $this->write(['type' => 'rows', 'table' => $table, 'rows' => $buffer]);
        }
    }

    /**
     * @return array{bytes: int, sha256: string}
     */
    public function finish(): array
    {
        gzclose($this->handle);
        $this->handle = null;

        return [
            'bytes' => (int) self::disk()->size($this->relativePath),
            'sha256' => self::checksum($this->relativePath),
        ];
    }

    /**
     * Drop a half-written backup (the purge failed and rolled back).
     */
    public function abort(): void
    {
        if ($this->handle !== null) {
            gzclose($this->handle);
            $this->handle = null;
        }

        if ($this->relativePath !== null) {
            self::disk()->delete($this->relativePath);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function read(string $relativePath): Generator
    {
        $handle = gzopen(self::absolutePath($relativePath), 'rb');

        if ($handle === false) {
            throw new PurgeAbortedException('The backup file could not be opened.');
        }

        try {
            while (($line = gzgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new PurgeAbortedException('The backup file is corrupted and cannot be restored.');
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    private static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('tenancy-purge.backup_disk'));

        return $disk;
    }
}
