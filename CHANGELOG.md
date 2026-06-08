# Changelog

All notable changes to `script-development/kendo-report-tool` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] — 2026-06-08

Inaugural public release — the kendo report-submission client library.

### Added

- Repository scaffold: PHP 8.4/8.5 CI matrix (audit → format → phpstan → test), Pint config, PHPStan level-max self-analysis, Pest/Testbench harness, tag-push release workflow.
- Auto-discovered `KendoReportToolServiceProvider` merging and publishing `config/report-tool.php` (`kendo_url`, `project`, `token`, `connect_timeout`, `timeout`, `swallow`; env defaults `REPORT_TOOL_*`).
- `KendoReports::submit(string $title, string $description, ?string $authorName = null, array $files = []): ?array` — the public client surface. Synchronous multipart POST to `{kendo_url}/api/projects/{project}/reports` with the Bearer `report:create` token; returns the decoded `201` report body.
- Multipart image upload: `files[]` accepts `Illuminate\Http\UploadedFile` instances or local file paths, sent as form parts alongside the scalar `title` / `description` / `author_name` fields (a null author is omitted).
- Bearer auth with bounded `connect_timeout` / `timeout` (a non-positive value floors to the default so a hung host never blocks the caller).
- Surfaced-failure-by-default: a non-`201` status or transport failure throws `ReportSubmissionException` (carrying the HTTP status, or `0` for a transport failure), unless `report-tool.swallow` (`REPORT_TOOL_SWALLOW=true`) is set — then the failure logs to the local PHP `error_log` and `null` is returned. No PII scrubbing (report content is intentional user input, sent verbatim).
