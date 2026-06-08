<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

use function config_path;

/**
 * Auto-discovered ServiceProvider (registered via composer extra.laravel.providers).
 *
 * Merges and publishes the package config, and binds the KendoReports client
 * singleton — its only collaborators are the framework's HTTP factory and config
 * repository (no facades), so a consuming app resolves it from the container.
 */
final class KendoReportToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/report-tool.php', 'report-tool');

        $this->app->singleton(KendoReports::class, fn(Application $app): KendoReports => new KendoReports(
            $app->make(HttpFactory::class),
            $app->make(Config::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/report-tool.php' => config_path('report-tool.php'),
            ], 'report-tool-config');
        }
    }
}
