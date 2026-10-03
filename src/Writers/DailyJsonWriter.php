<?php

declare(strict_types=1);

namespace LogMonitor\Writers;

use DateTimeImmutable;
use LogMonitor\Contracts\EventWriter;
use RuntimeException;

final class DailyJsonWriter implements EventWriter
{
    public function __construct(private readonly string $path) {}

    public function write(array $event): void
    {
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES)."\n";
        $date = new DateTimeImmutable($event['timestamp'] ?? 'now');
        $this->ensureDirectory();
        $file = $this->path.'/monitor-'.$date->format('Y-m-d').'.log';
        if (is_link($file)) {
            throw new RuntimeException('Refusing to write a symlinked monitoring file.');
        }
        $stream = @fopen($file, 'ab');
        if ($stream === false) {
            throw new RuntimeException('Cannot open monitoring log: '.$file);
        }
        try {
            @chmod($file, 0600);
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Cannot lock monitoring log.');
            }
            $offset = 0;
            while ($offset < strlen($line)) {
                $written = fwrite($stream, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot append monitoring event.');
                }
                $offset += $written;
            }
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }
    }

    public function ensureDirectory(): void
    {
        if (! is_dir($this->path) && ! @mkdir($this->path, 0700, true) && ! is_dir($this->path)) {
            throw new RuntimeException('Cannot create monitoring directory: '.$this->path);
        }
        if (! is_writable($this->path)) {
            throw new RuntimeException('Monitoring directory is not writable: '.$this->path);
        }
    }
}
