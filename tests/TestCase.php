<?php

declare(strict_types=1);

namespace LogMonitor\Tests;

use Illuminate\Queue\Queue;
use LogMonitor\MonitorServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected string $logPath;

    protected string $configPath;

    protected function getPackageProviders($app): array
    {
        return [MonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->logPath = sys_get_temp_dir().'/monitor-test-'.bin2hex(random_bytes(8));
        $this->configPath = $this->logPath.'/config';
        $app->useConfigPath($this->configPath);
        $app['config']->set('monitor.storage.path', $this->logPath.'/logs');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function tearDown(): void
    {
        Queue::createPayloadUsing(null);
        if (isset($this->logPath) && is_dir($this->logPath)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->logPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->logPath);
        }
        parent::tearDown();
    }

    protected function events(?string $type = null): array
    {
        $events = [];
        foreach (glob($this->logPath.'/logs/monitor-*.log') ?: [] as $file) {
            foreach (file($file) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($type === null || $event['type'] === $type) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }
}
