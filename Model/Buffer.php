<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Redirects\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Where a storefront 404 goes first: one line appended to
 * var/zero_redirects/404.log (no database on the request). Above MAX_BYTES,
 * waiting for cron, new 404s are dropped: a flood of random addresses fills
 * neither the disk nor, later, the table. take() hands the lines over and
 * starts a new file (a rename, so a request appending meanwhile isn't lost).
 * A taken file a crashed run left behind (404.log.<pid>.<random>, untouched
 * for LEFTOVER_SECONDS) is read by the next take() first.
 */
class Buffer
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const DIR = 'zero_redirects';
    private const FILE = '404.log';
    /** A taken file older than this belongs to a run that died: no run reads one for so long. */
    private const LEFTOVER_SECONDS = 600;

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function append(int $storeId, string $path, ?string $referer): void
    {
        $file = $this->file();
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            return;
        }
        $line = json_encode([$storeId, $path, $referer, time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // One short write with O_APPEND: lines from parallel requests don't interleave.
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // A full disk or a directory made by a parallel request a moment ago: a 404 not noted, no error on the page.
        }
    }

    /**
     * The buffered 404s, removed from the buffer: first what a crashed run left, then the current file.
     *
     * @return \Generator<array{0: int, 1: string, 2: string|null, 3: int}>
     */
    public function take(): \Generator
    {
        $file = $this->file();
        foreach (glob($file . '.*') ?: [] as $leftover) {
            clearstatcache(true, $leftover);
            $mtime = is_file($leftover) ? filemtime($leftover) : false;
            if ($mtime === false || $mtime >= time() - self::LEFTOVER_SECONDS) {
                continue; // being read by a run right now (its take() touched it)
            }
            $claimed = $this->claim($leftover);
            if ($claimed !== null) {
                yield from $this->read($claimed);
            }
        }
        if (!is_file($file)) {
            return;
        }
        $taken = $this->claim($file);
        if ($taken !== null) {
            yield from $this->read($taken);
        }
    }

    /**
     * The file renamed to a name of this run's, its time set to now (a leftover is told by its age): null when
     * another run took it a moment ago.
     */
    private function claim(string $path): ?string
    {
        $taken = $this->file() . '.' . getmypid() . '.' . bin2hex(random_bytes(4));
        try {
            if (!rename($path, $taken)) {
                return null;
            }
            touch($taken);
        } catch (\Throwable) {
            return null;
        }

        return $taken;
    }

    /**
     * The lines of a taken file, then the file removed.
     *
     * @return \Generator<array{0: int, 1: string, 2: string|null, 3: int}>
     */
    private function read(string $taken): \Generator
    {
        try {
            $handle = fopen($taken, 'rb');
        } catch (\Throwable) {
            return;
        }
        if (!$handle) {
            return;
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row) && count($row) === 4) {
                yield [(int) $row[0], (string) $row[1], $row[2] !== null ? (string) $row[2] : null, (int) $row[3]];
            }
        }
        fclose($handle);
        try {
            unlink($taken);
        } catch (\Throwable) {
            // Left behind: read once more by a later run (after LEFTOVER_SECONDS), harmless for a 404 count.
        }
    }

    private function file(): string
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath(self::DIR . '/' . self::FILE);
    }
}
