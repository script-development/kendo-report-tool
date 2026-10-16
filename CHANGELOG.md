# Changelog

All notable changes to `script-development/kendo-report-tool` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ReportSubmissionException::status()` (the HTTP status, `0` when no response was received) and `ReportSubmissionException::errors()` (the field-keyed validation errors of a rejection), so a caller can tell an invalid payload from a token/project problem without parsing the message. A rejection with validation errors names the fields in its message.
- Client-side file cap `KendoReports::MAX_FILES` (5, the server's own limit): a `submit()` with more files is refused before sending, as a `ReportSubmissionException` keyed by `files` (swallowed like any other failure when `swallow=true`).
- `ReportConfigurationException`: `submit()` checks `kendo_url` (an absolute `http(s)` URL), `project` and `token` (non-blank) before sending and throws this, naming the key and env var, instead of failing as a transport error.

- `.gitattributes` with `export-ignore` on the non-runtime tree (tests, CI, tool configs, `CLAUDE.md`), so the dist archive installed into consumers' `vendor/` carries only `src/`, `config/`, the manifest and the docs.
- CI step `composer validate --strict`.

### Changed

- A misconfigured install is no longer swallowed: the `ReportConfigurationException` above is thrown whatever `report-tool.swallow` says, and it is not a `ReportSubmissionException`, so a `catch (ReportSubmissionException)` no longer hides it.
- `report-tool.swallow` is parsed as a boolean (`"false"`, `"0"`, `"off"` are false; anything unrecognised is false) instead of raw-cast, so a config value of the string `"false"` no longer turns swallowing on.
- CI coverage gate: `pest --coverage --min=98` with the `pcov` driver on the PHP 8.4/8.5 matrix (CI) and the release-verify job. Floor is the currently-measured 98% (the string-path `file_get_contents` attach branch in `KendoReports` is the sole uncovered line, a parked item); aspiration `--min=100`, ratcheted up once that branch is covered.
- Token-confidentiality regression tests: assert the `report:create` Bearer token never surfaces in a `ReportSubmissionException` message (rejection or transport failure) or in the `error_log` swallow path.

## [0.1.0] — 2026-06-08

Inaugural public release — the kendo report-submission client library.

### Added

- Repository scaffold: PHP 8.4/8.5 CI matrix (audit → format → phpstan → test), Pint config, PHPStan level-max self-analysis, Pest/Testbench harness, tag-push release workflow.
- Auto-discovered `KendoReportToolServiceProvider` merging and publishing `config/report-tool.php` (`kendo_url`, `project`, `token`, `connect_timeout`, `timeout`, `swallow`; env defaults `REPORT_TOOL_*`).
- `KendoReports::submit(string $title, string $description, ?string $authorName = null, array $files = []): ?array` — the public client surface. Synchronous multipart POST to `{kendo_url}/api/projects/{project}/reports` with the Bearer `report:create` token; returns the decoded `201` report body.
- Multipart image upload: `files[]` accepts `Illuminate\Http\UploadedFile` instances or local file paths, sent as form parts alongside the scalar `title` / `description` / `author_name` fields (a null author is omitted).
- Bearer auth with bounded `connect_timeout` / `timeout` (a non-positive value floors to the default so a hung host never blocks the caller).
- Surfaced-failure-by-default: a non-`201` status or transport failure throws `ReportSubmissionException` (carrying the HTTP status, or `0` for a transport failure), unless `report-tool.swallow` (`REPORT_TOOL_SWALLOW=true`) is set — then the failure logs to the local PHP `error_log` and `null` is returned. No PII scrubbing (report content is intentional user input, sent verbatim).
