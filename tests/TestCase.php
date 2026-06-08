<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use ScriptDevelopment\KendoReportTool\KendoReportToolServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            KendoReportToolServiceProvider::class,
        ];
    }
}
