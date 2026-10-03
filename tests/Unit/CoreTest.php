<?php

declare(strict_types=1);

namespace LogMonitor\Tests\Unit;

use LogMonitor\Monitoring\Fingerprint;
use LogMonitor\Monitoring\Sanitizer;
use LogMonitor\Monitoring\TraceContext;
use LogMonitor\Writers\DailyJsonWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreTest extends TestCase
{
    public function test_daily_writer_creates_private_directory_and_appends_json_lines(): void
    {
        $path = sys_get_temp_dir().'/monitor-unit-'.bin2hex(random_bytes(8));
        try {
            $writer = new DailyJsonWriter($path);
            $writer->write(['timestamp' => '2026-10-01T23:59:59+07:00', 'type' => 'info', 'message' => "hello\nworld"]);
            $writer->write(['timestamp' => '2026-10-01T23:59:59+07:00', 'type' => 'warning']);
            $writer->write(['timestamp' => '2026-10-02T00:00:00+07:00', 'type' => 'response']);
            $lines = file($path.'/monitor-2026-10-01.log');
            self::assertCount(2, $lines);
            self::assertSame("hello\nworld", json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['message']);
            self::assertFileExists($path.'/monitor-2026-10-02.log');
            self::assertSame(0600, fileperms($path.'/monitor-2026-10-01.log') & 0777);
        } finally {
            foreach (glob($path.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($path);
        }
    }

    public function test_writer_rejects_symlinks(): void
    {
        $path = sys_get_temp_dir().'/monitor-link-'.bin2hex(random_bytes(8));
        mkdir($path);
        file_put_contents($path.'/normal.log', 'safe');
        symlink($path.'/normal.log', $path.'/monitor-2026-10-01.log');
        try {
            (new DailyJsonWriter($path))->write(['timestamp' => '2026-10-01']);
            self::fail('Symlink should be rejected.');
        } catch (RuntimeException) {
            self::assertSame('safe', file_get_contents($path.'/normal.log'));
        } finally {
            unlink($path.'/monitor-2026-10-01.log');
            unlink($path.'/normal.log');
            rmdir($path);
        }
    }

    public function test_sanitizer_masks_nested_custom_fields_and_bounds_data(): void
    {
        $sanitizer = new Sanitizer(['masked_fields' => ['password', 'authorization', 'token', 'secret', 'custom_pin'], 'max_items' => 5, 'max_depth' => 3]);
        $value = $sanitizer->sanitize(['password' => 'x', 'nested' => ['AUTHORIZATION' => 'Bearer abc', 'accessToken' => 't', 'custom-pin' => '123'], 'object' => new \stdClass]);
        self::assertSame('[REDACTED]', $value['password']);
        self::assertSame(['AUTHORIZATION' => '[REDACTED]', 'accessToken' => '[REDACTED]', 'custom-pin' => '[REDACTED]'], $value['nested']);
        self::assertSame('[UNSERIALIZABLE]', $value['object']);
        self::assertStringNotContainsString('abc', $sanitizer->text('Bearer abc token=xyz https://user:pass@host/path?secret=foo'));
        self::assertSame(['[REDACTED]', '[REDACTED]'], $sanitizer->bindings(['secret', 123]));
        self::assertStringNotContainsString('secret', $sanitizer->sql("select * from users where password = 'secret' and id = 123"));
        self::assertSame('select ?, ?', $sanitizer->sql('select $$secret$$, $tag$secret$tag$'));
        self::assertStringNotContainsString('secret', $sanitizer->text('SQL error: select $$secret$$'));
        self::assertStringNotContainsString('dXNlcjpwYXNz', $sanitizer->text('Authorization: Basic dXNlcjpwYXNz'));
        self::assertStringNotContainsString('4111', $sanitizer->text('Card 4111 1111 1111 1111'));
    }

    public function test_fingerprint_normalizes_ids_uuids_ulids_and_timestamps(): void
    {
        $fingerprint = new Fingerprint;
        self::assertSame(
            $fingerprint->make('Error', 'Order 123 at 2026-10-01T12:00:00+07:00 550e8400-e29b-41d4-a716-446655440000 01JXYZ8D91AAAAAAAAAAAAAAAA', 'app/Order.php', 84),
            $fingerprint->make('Error', 'Order 456 at 2026-10-02T13:00:00+07:00 550e8400-e29b-41d4-a716-446655440001 01JXYZ8D91BBBBBBBBBBBBBBBB', 'app/Order.php', 84),
        );
    }

    public function test_context_is_unique_and_clears_every_lifecycle_field(): void
    {
        $context = new TraceContext;
        $first = $context->start();
        self::assertMatchesRegularExpression('/^req_[0-9A-HJKMNP-TV-Z]{26}$/', $first);
        $context->queries = 10;
        $context->breadcrumbs = 4;
        $context->metadata = ['path' => '/old'];
        $exception = new RuntimeException;
        self::assertTrue($context->markReported($exception));
        self::assertFalse($context->markReported($exception));
        self::assertNotSame($first, $context->start());
        self::assertSame(0, $context->queries);
        self::assertSame(0, $context->breadcrumbs);
        self::assertSame([], $context->metadata);
        self::assertTrue($context->markReported($exception));
        $context->reset();
        self::assertNull($context->traceId);
    }
}
