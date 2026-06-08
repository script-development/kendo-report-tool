<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool;

use Illuminate\Support\ServiceProvider;

use function config_path;

/**
 * Auto-discovered ServiceProvider (registered via composer extra.laravel.providers).
 *
 * Scaffold stage: merges and publishes the package config. The report client
 * binding + public surface land in the client issue (KD report-tool #2).
 */
final class KendoReportToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/report-tool.php', 'report-tool');
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
