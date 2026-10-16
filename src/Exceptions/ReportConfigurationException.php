<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown by submit() when the package is not configured to reach kendo.
 *
 * Deliberately not a ReportSubmissionException and never swallowed, even with
 * report-tool.swallow on: swallowing a failed send is the design, swallowing a
 * deployment that can never send is not. The message names the config key and
 * env var, never the configured value.
 */
final class ReportConfigurationException extends RuntimeException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('kendo-report-tool is not configured: report-tool.%s is empty (set %s).', $key, $env));
    }

    public static function invalidUrl(): self
    {
        return new self('kendo-report-tool is not configured: report-tool.kendo_url must be an absolute http(s) URL with no query or fragment (set REPORT_TOOL_KENDO_URL).');
    }
}
