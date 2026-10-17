<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool\Tests\Fixtures;

use function min;
use function str_repeat;

/**
 * A stream wrapper that reports a size from url_stat() and counts every open,
 * so a test can prove a file was sized but never read.
 */
final class RecordingStreamWrapper
{
    public const string PROTOCOL = 'krt-recording';

    public static int $size = 0;

    public static int $opens = 0;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function reset(int $size): void
    {
        self::$size = $size;
        self::$opens = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens++;

        return true;
    }

    public function stream_read(int $count): string
    {
        $length = min($count, self::$size - $this->position);
        $this->position += $length;

        return str_repeat('x', $length);
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::$size;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return $this->url_stat('', 0);
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100_644, 'size' => self::$size];
    }
}
