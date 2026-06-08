# Changelog

All notable changes to `script-development/kendo-report-tool` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Repository scaffold: PHP 8.4/8.5 CI matrix (audit → format → phpstan → test), Pint config, PHPStan level-max self-analysis, Pest/Testbench harness, tag-push release workflow.
- Auto-discovered `KendoReportToolServiceProvider` merging and publishing `config/report-tool.php` (`kendo_url`, `project`, `token`, `connect_timeout`, `timeout`, `swallow`; env defaults `REPORT_TOOL_*`).
