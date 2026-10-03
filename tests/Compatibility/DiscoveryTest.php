<?php

declare(strict_types=1);

namespace LogMonitor\Tests\Compatibility;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Facade;
use LogMonitor\Monitoring\TraceContext;
use LogMonitor\MonitorServiceProvider;
use PHPUnit\Framework\TestCase;

/** Boot a consumer using Composer discovery metadata and Laravel's real application builder. */
final class DiscoveryTest extends TestCase
{
    public function test_discovered_package_installs_and_traces_with_modern_application_bootstrap(): void
    {
        $path = sys_get_temp_dir().'/monitor-consumer-'.bin2hex(random_bytes(8));
        $originalContainer = Container::getInstance();
        $originalFacadeApp = Facade::getFacadeApplication();
        foreach (['bootstrap/cache', 'config', 'storage/logs', 'vendor/composer'] as $directory) {
            mkdir($path.'/'.$directory, 0700, true);
        }
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($path.'/composer.json', '{}');
        file_put_contents($path.'/vendor/composer/installed.json', json_encode(['packages' => [$manifest]], JSON_THROW_ON_ERROR));
        file_put_contents($path.'/bootstrap/providers.php', '<?php return [];');
        file_put_contents($path.'/config/app.php', "<?php return ['env' => 'testing', 'debug' => false, 'timezone' => 'UTC', 'key' => 'test'];");
        try {
            $app = Application::configure($path)
                ->withRouting(using: static function (): void {
                    app('router')->get('/discovery', static fn () => response('discovered', 202));
                })
                ->withMiddleware(static fn (Middleware $middleware) => $middleware->use([]))
                ->withExceptions()
                ->create();
            $console = $app->make(ConsoleKernel::class);
            $console->bootstrap();
            self::assertTrue($app->providerIsLoaded(MonitorServiceProvider::class));
            self::assertSame(0, $console->call('monitor:install', ['--no-interaction' => true]));
            self::assertFileExists($path.'/config/monitor.php');
            $kernel = $app->make(Kernel::class);
            $request = Request::create('/discovery');
            $response = $kernel->handle($request);
            self::assertSame(202, $response->getStatusCode());
            self::assertSame('discovered', $response->getContent());
            self::assertMatchesRegularExpression('/^req_[0-9A-HJKMNP-TV-Z]{26}$/', $response->headers->get('X-Trace-ID'));
            self::assertNull($app->make(TraceContext::class)->traceId);
            self::assertCount(1, glob($path.'/storage/logs/monitor/monitor-*.log'));
            self::assertSame(0, $console->call('monitor:status', ['--no-ansi' => true]));
            self::assertStringContainsString('APPLICATION MONITOR', $console->output());
        } finally {
            restore_error_handler();
            restore_exception_handler();
            Queue::createPayloadUsing(null);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($originalFacadeApp);
            Container::setInstance($originalContainer);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($path);
        }
    }
}
