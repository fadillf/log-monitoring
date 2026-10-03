<?php

declare(strict_types=1);

namespace LogMonitor\Support;

use RuntimeException;

final class TailFollower
{
    /** @var resource|null */
    private $stream = null;

    private ?string $file = null;

    private string $buffer = '';

    private bool $discard = false;

    private bool $initialized = false;

    public bool $caughtUp = true;

    public function __construct(private readonly LogReader $reader, private readonly string $path, private readonly bool $fromStart = false) {}

    /** Read at most 64 KiB per poll; preserve partial lines until the next poll. */
    public function poll(?string $date = null): array
    {
        if (file_exists($this->path) && ! is_dir($this->path)) {
            throw new RuntimeException('Monitoring storage path is not a directory: '.$this->path);
        }
        if (is_dir($this->path) && ! is_readable($this->path)) {
            throw new RuntimeException('Monitoring directory is not readable: '.$this->path);
        }
        $file = $this->path.'/monitor-'.($date ?? date('Y-m-d')).'.log';
        clearstatcache(true, $file);
        $stat = @stat($file);
        if (is_link($file)) {
            throw new RuntimeException('Refusing to follow a symlinked monitoring log.');
        }
        $current = $this->stream !== null ? fstat($this->stream) : false;
        if ($file !== $this->file || ($stat !== false && $current !== false && ($stat['ino'] !== $current['ino'] || $stat['dev'] !== $current['dev']))) {
            $this->close();
            $this->file = $file;
        }
        if ($this->stream === null && $stat !== false) {
            $this->stream = @fopen($file, 'rb') ?: null;
            if ($this->stream === null) {
                throw new RuntimeException('Cannot follow monitoring log: '.$file);
            }
            if (! $this->initialized && ! $this->fromStart) {
                fseek($this->stream, 0, SEEK_END);
            }
        }
        $this->initialized = true;
        if ($this->stream === null) {
            $this->caughtUp = true;

            return [];
        }
        $position = ftell($this->stream);
        if ($stat !== false && $position > $stat['size']) {
            rewind($this->stream);
            $this->buffer = '';
            $this->discard = false;
        }
        // fseek clears PHP's cached EOF when the file has grown.
        fseek($this->stream, (int) ftell($this->stream));
        $chunk = fread($this->stream, 65536);
        if ($chunk === false) {
            throw new RuntimeException('Cannot stream monitoring log.');
        }
        $this->caughtUp = strlen($chunk) < 65536;
        $this->buffer .= $chunk;
        $events = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($this->discard) {
                $this->discard = false;

                continue;
            }
            $event = $this->reader->parse($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if (strlen($this->buffer) > $this->reader->maxLineBytes()) {
            $this->buffer = '';
            $this->discard = true;
        }

        return $events;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->buffer = '';
        $this->discard = false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
