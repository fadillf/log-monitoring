<?php

declare(strict_types=1);

namespace LogMonitor\Support;

use DateTimeImmutable;
use Generator;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Throwable;

final class LogReader
{
    public bool $truncated = false;

    public function __construct(private readonly Repository $config) {}

    /** @return list<string> */
    public function files(?DateTimeImmutable $since = null): array
    {
        $path = $this->config->get('monitor.storage.path');
        if (! is_dir($path)) {
            if (file_exists($path)) {
                throw new RuntimeException('Monitoring storage path is not a directory: '.$path);
            }

            return [];
        }
        if (! is_readable($path)) {
            throw new RuntimeException('Monitoring directory is not readable: '.$path);
        }
        $files = [];
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isLink() || ! $entry->isFile() || ! preg_match('/^monitor-(\d{4}-\d{2}-\d{2})\.log$/', $entry->getFilename(), $match)) {
                continue;
            }
            if ($since !== null && $match[1] < $since->modify('-1 day')->format('Y-m-d')) {
                continue;
            }
            $files[] = $entry->getPathname();
        }
        rsort($files, SORT_STRING);

        return $files;
    }

    /** Events are returned newest first, with a hard bound on scanned lines. */
    public function recent(DateTimeImmutable $since, ?callable $filter = null, ?int $limit = null): Generator
    {
        $this->truncated = false;
        $scanned = $matched = 0;
        $max = max(1, (int) $this->config->get('monitor.console.max_events', 100000));
        foreach ($this->files($since) as $file) {
            foreach ($this->reverseLines($file) as $line) {
                if (++$scanned > $max) {
                    $this->truncated = true;

                    return;
                }
                $event = $this->parse($line);
                if ($event === null) {
                    continue;
                }
                try {
                    if (new DateTimeImmutable($event['timestamp']) < $since) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
                if ($filter !== null && ! $filter($event)) {
                    continue;
                }
                yield $event;
                if ($limit !== null && ++$matched >= $limit) {
                    return;
                }
            }
        }
    }

    public function parse(string $line): ?array
    {
        if (strlen($line) > $this->maxLineBytes()) {
            return null;
        }
        $event = json_decode($line, true);
        if (! is_array($event) || ! isset($event['timestamp'], $event['type']) || ! is_string($event['timestamp']) || ! is_string($event['type'])) {
            return null;
        }

        return $event;
    }

    public function maxLineBytes(): int
    {
        return max(1024, (int) $this->config->get('monitor.console.max_line_bytes', 262144));
    }

    private function reverseLines(string $file): Generator
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read monitoring log: '.$file);
        }
        try {
            $position = (int) fstat($stream)['size'];
            $buffer = '';
            $discard = false;
            while ($position > 0) {
                $length = min(8192, $position);
                $position -= $length;
                fseek($stream, $position);
                $chunk = fread($stream, $length);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot stream monitoring log: '.$file);
                }
                $buffer = $chunk.$buffer;
                while (($newline = strrpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, $newline + 1);
                    $buffer = substr($buffer, 0, $newline);
                    if ($discard) {
                        $discard = false;
                        yield ''; // Count oversized records against the scan bound.
                    } elseif ($line !== '') {
                        yield strlen($line) <= $this->maxLineBytes() ? $line : '';
                    }
                }
                if (strlen($buffer) > $this->maxLineBytes()) {
                    $buffer = '';
                    $discard = true;
                }
            }
            if ($buffer !== '' && ! $discard) {
                yield $buffer;
            }
        } finally {
            fclose($stream);
        }
    }
}
