<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoReportTool\KendoReportToolServiceProvider;

it('exposes an auto-discoverable service provider', function(): void {
    expect(class_exists(KendoReportToolServiceProvider::class))->toBeTrue();
});
