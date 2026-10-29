<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Redirects\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Redirects\Model\Buffer;
use Zero\Redirects\Model\Importer;
use Zero\Redirects\Model\Log;
use Zero\Redirects\Model\Suggestions;

class ImporterTest extends TestCase
{
    private Buffer&MockObject $buffer;
    private Log&MockObject $log;
    private Suggestions&MockObject $suggestions;
    private Importer $importer;

    protected function setUp(): void
    {
        $this->buffer = $this->createMock(Buffer::class);
        $this->log = $this->createMock(Log::class);
        $this->suggestions = $this->createMock(Suggestions::class);
        $this->importer = new Importer($this->buffer, $this->log, $this->suggestions);
    }

    public function testRepeatedHitsOnOnePathAreFoldedIntoOneRow(): void
    {
        $this->buffer->method('take')->willReturnCallback(fn () => yield from [
            [1, '/old', null, 100],
            [1, '/old', 'https://a.example/', 300],
            [1, '/old', null, 200],
            [2, '/old', null, 150],
        ]);
        $this->log->method('withoutSuggestion')->willReturn([]);
        $this->log->expects(self::once())->method('add')->with([
            ['store_id' => 1, 'path' => '/old', 'referer' => 'https://a.example/', 'hits' => 3, 'first' => 100, 'last' => 300],
            ['store_id' => 2, 'path' => '/old', 'referer' => null, 'hits' => 1, 'first' => 150, 'last' => 150],
        ]);
        $this->log->expects(self::once())->method('cap')->with(Importer::MAX_OPEN);

        $this->importer->import();
    }

    public function testAnEmptyBufferWritesNothing(): void
    {
        $this->buffer->method('take')->willReturnCallback(fn () => yield from []);
        $this->log->method('withoutSuggestion')->willReturn([]);
        $this->log->expects(self::never())->method('add');
        $this->log->expects(self::never())->method('cap');

        $this->importer->import();
    }

    public function testRowsWithoutSuggestionGetTheirLikelyTarget(): void
    {
        $this->buffer->method('take')->willReturnCallback(fn () => yield from []);
        $this->log->expects(self::once())->method('withoutSuggestion')->with(5)
            ->willReturn([['log_id' => '9', 'store_id' => '2', 'path' => '/gone']]);
        $this->suggestions->expects(self::once())->method('forPath')->with(2, '/gone')->willReturn('/new');
        $this->log->expects(self::once())->method('setSuggestion')->with(9, '/new');

        $this->importer->import(5);
    }
}
