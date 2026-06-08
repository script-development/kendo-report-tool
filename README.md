# kendo-report-tool

[![CI](https://github.com/script-development/kendo-report-tool/actions/workflows/ci.yml/badge.svg)](https://github.com/script-development/kendo-report-tool/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/script-development/kendo-report-tool.svg)](LICENSE)

Laravel client library for submitting user reports/feedback into a kendo project — config, Bearer auth, multipart image upload, and a surfaced send result, installable via Composer across Script Development Laravel territories.

## Why

kendo ships a reports endpoint (`POST /api/projects/{project}/reports`) that accepts machine submissions authenticated with a `report:create` project token. This library is the secure server-side transport: a consuming app's backend holds the token (which must **never** ship to the browser) and relays a report — title, description, optional author name, optional screenshots — from the app's own feedback form into the kendo project.

Unlike [`kendo-error-tracker`](https://github.com/script-development/kendo-error-tracker) (auto-captured, fire-and-forget exception telemetry), a report is an **explicit human submission**: the send is synchronous and its outcome is surfaced, so the app can tell the user whether it sent.

## Installation

```bash
composer require script-development/kendo-report-tool
```

The `KendoReportToolServiceProvider` is auto-discovered via Laravel package discovery. Publish the config if you want to tune it:

```bash
php artisan vendor:publish --tag=report-tool-config
```

## Configuration

Set the environment variables (the config reads `REPORT_TOOL_*`):

| Env var | Config key | Description |
|---|---|---|
| `REPORT_TOOL_KENDO_URL` | `kendo_url` | Base URL of your kendo tenant — `https://{tenant}.kendo.dev`. |
| `REPORT_TOOL_PROJECT` | `project` | The kendo **project id** that owns the reports (the `{project}` route-key; kendo binds it by id). |
| `REPORT_TOOL_TOKEN` | `token` | A kendo project token carrying the `report:create` ability (Bearer). |
| `REPORT_TOOL_CONNECT_TIMEOUT` | `connect_timeout` | Seconds to wait while connecting to the kendo host (default `2`). |
| `REPORT_TOOL_TIMEOUT` | `timeout` | Total seconds to wait for the POST (default `5`). |
| `REPORT_TOOL_SWALLOW` | `swallow` | `false` (default) surfaces a failed send to the caller; `true` swallows it (telemetry mode). |

```dotenv
REPORT_TOOL_KENDO_URL=https://script.kendo.dev
REPORT_TOOL_PROJECT=1
REPORT_TOOL_TOKEN=your-project-token
```

## Preconditions

- The consuming kendo **project must have the `report-tool` feature active** — otherwise the endpoint rejects the submission.
- The token must be minted **under that project** and carry the `report:create` ability. A token used against a different project's route is rejected (`422`).

## Minting a project token

1. Open the kendo project's **API token** settings.
2. Create a project token — it carries the `report:create` ability.
3. Copy the token into `REPORT_TOOL_TOKEN`.

A submission made with a `report:create` token is recorded with `source = Api`; the human author you pass is surfaced via `author_name` (the token owner is not treated as the creator).

## Usage

> The `KendoReports::submit()` client surface lands in the first client release. This scaffold ships the config + service-provider skeleton; see [`PLAN.md`](https://github.com/script-development/) in Agent-OS for the build sequence.

## Contributing

`composer test` · `composer phpstan` · `composer format:check` — all three gate CI on PHP 8.4 and 8.5. `main` is always release-ready; PRs update `CHANGELOG.md` under `[Unreleased]`.
