<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Redirects\Test\Unit\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\TestCase;
use Zero\Redirects\Model\Buffer;

/**
 * take(): what a crashed run left (a taken file untouched for 10 minutes) is read first and removed, a file a live
 * run is reading is left to it, then the current buffer is taken.
 */
class BufferTest extends TestCase
{
    private string $dir;
    private Buffer $buffer;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zero-redirects-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/zero_redirects', 0777, true);
        $read = $this->createStub(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturnCallback(fn (string $path) => $this->dir . '/' . $path);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($read);
        $this->buffer = new Buffer($filesystem);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/zero_redirects/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/zero_redirects');
        rmdir($this->dir);
    }

    public function testAnOldLeftoverIsReadFirstAndDeletedThenTheCurrentFile(): void
    {
        $this->write('404.log.4242.deadbeef', [[1, '/crashed', null, 100]], time() - 900);
        $this->buffer->append(2, '/now', 'https://a.example/');

        $rows = iterator_to_array($this->buffer->take(), false);

        $this->assertSame([1, '/crashed', null, 100], $rows[0]);
        $this->assertSame([2, '/now', 'https://a.example/'], array_slice($rows[1], 0, 3));
        $this->assertCount(2, $rows);
        $this->assertSame([], $this->files(), 'both files are removed');
    }

    public function testALeftoverYoungerThanTenMinutesIsNotTouched(): void
    {
        $mtime = time() - 60;
        $this->write('404.log.4242.cafebabe', [[1, '/being-read', null, 100]], $mtime);
        $this->buffer->append(1, '/now', null);

        $rows = iterator_to_array($this->buffer->take(), false);

        $this->assertSame(['/now'], array_column($rows, 1));
        $this->assertSame(['404.log.4242.cafebabe'], $this->files());
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->dir . '/zero_redirects/404.log.4242.cafebabe'));
    }

    public function testATakenFileIsTouchedSoItIsNotTakenForALeftoverWhileRead(): void
    {
        $this->write('404.log', [[1, '/a', null, 100], [1, '/b', null, 101]], time() - 3600);

        $take = $this->buffer->take();
        $this->assertSame('/a', $take->current()[1]);

        $taken = $this->files();
        $this->assertCount(1, $taken);
        $this->assertMatchesRegularExpression('/^404\.log\.\d+\.[0-9a-f]{8}\z/', $taken[0]);
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 5, filemtime($this->dir . '/zero_redirects/' . $taken[0]));
        // A second run meanwhile neither reads it again nor finds anything else.
        $this->assertSame([], iterator_to_array($this->buffer->take(), false));

        $take->next();
        $this->assertSame('/b', $take->current()[1]);
        $take->next();
        $this->assertFalse($take->valid());
        $this->assertSame([], $this->files());
    }

    public function testNothingBufferedYieldsNothing(): void
    {
        $this->assertSame([], iterator_to_array($this->buffer->take(), false));
    }

    public function testBrokenLinesAreSkipped(): void
    {
        file_put_contents($this->dir . '/zero_redirects/404.log', "not json\n[1,\"/ok\",null,5]\n[1,2]\n");

        $this->assertSame([[1, '/ok', null, 5]], iterator_to_array($this->buffer->take(), false));
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: string|null, 3: int}> $rows
     */
    private function write(string $name, array $rows, int $mtime): void
    {
        $path = $this->dir . '/zero_redirects/' . $name;
        file_put_contents($path, implode('', array_map(static fn (array $r) => json_encode($r) . "\n", $rows)));
        touch($path, $mtime);
    }

    /**
     * @return string[] the files in the buffer's directory
     */
    private function files(): array
    {
        return array_map('basename', glob($this->dir . '/zero_redirects/*') ?: []);
    }
}
