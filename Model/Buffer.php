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
 */
class Buffer
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const DIR = 'zero_redirects';
    private const FILE = '404.log';

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
     * The buffered 404s, removed from the buffer.
     *
     * @return \Generator<array{0: int, 1: string, 2: string|null, 3: int}>
     */
    public function take(): \Generator
    {
        $file = $this->file();
        if (!is_file($file)) {
            return;
        }
        $taken = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(4));
        try {
            // Another cron run may have taken the file a moment ago: then there is nothing to read.
            $handle = rename($file, $taken) ? fopen($taken, 'rb') : false;
        } catch (\Throwable) {
            return;
        }
        if ($handle) {
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
                // Left behind: read once more by the next run, which is harmless for a 404 count.
            }
        }
    }

    private function file(): string
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath(self::DIR . '/' . self::FILE);
    }
}
