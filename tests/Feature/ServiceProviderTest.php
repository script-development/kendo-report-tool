<?php

declare(strict_types = 1);

it('merges the package config with env defaults', function(): void {
    expect(config('report-tool.connect_timeout'))->toBe(2)
        ->and(config('report-tool.timeout'))->toBe(5)
        ->and(config('report-tool.swallow'))->toBeFalse();
});
