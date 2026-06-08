# kendo-report-tool — kendo report-submission client library

Distributable Laravel client library that submits user reports/feedback into a kendo project's reports endpoint. Sister to `kendo-error-tracker` in artifact shape (standalone Composer package, public packagist, MIT, Pest/PHPStan/Pint, `type: library`, ServiceProvider auto-discovery) — but for **explicit human submissions**, not auto-captured telemetry.

## Stack

- **Language:** PHP 8.4+.
- **Framework:** Laravel package (`illuminate/* ^11–13`), ServiceProvider auto-registered via `extra.laravel.providers`.
- **Test:** Pest 3 over Orchestra Testbench (Feature tests boot a Laravel app; Unit tests are pure).
- **Static analysis:** PHPStan 2.x, level max, self-analysis on `src/`.
- **Format:** Pint (canonical war-room config).
- **Publish:** Auto-sync to public packagist.org via repository webhook (tag-push releases via `release.yml`). First-time packagist submission is a manual step gated on the `script-development` vendor owner (Gerard / `Goosterhof`).
- **Default branch:** `main`.

## Server contract

The client targets the kendo reports ingestion endpoint (already live — no kendo-side work):

- **Route:** `POST {kendo_url}/api/projects/{project}/reports`.
- **`{project}` route-key:** the project **id**.
- **Auth:** Bearer — a kendo project token carrying the `report:create` ability. A `report:create` submission is recorded with `source = Api`; the human author is surfaced via `author_name`.
- **Feature gate:** the project must have the `report-tool` feature active (`EnsureFeatureActive:report-tool`).
- **Body (multipart):** `title` (required, ≤255), `description` (required, ≤65535), `author_name` (nullable, 1–255), `files[]` (≤5 images, jpg/jpeg/png/bmp/gif/tiff/webp, ≤3mb each).
- **Success:** `201 Created` + the created report resource (returned to the caller).
- **Failures:** `401`/`403` (token), `422` (token/project mismatch or validation), `5xx`, timeout — surfaced to the caller by default (`swallow=false`).

## Conventions

- **Namespace:** `ScriptDevelopment\KendoReportTool\` (PSR-4, `src/`).
- **Config keys live in `config/report-tool.php`** with `REPORT_TOOL_*` env defaults.
- **Surface failures by default.** A report has a human waiting on confirmation — `submit()` reports a failed send unless `swallow=true`. (Contrast error-tracker, which always swallows.)
- **Synchronous send.** The caller needs the `201` + report id back, so there is no queue/async mode and no `illuminate/bus`/`illuminate/queue` dependency.
- **No PII scrubbing.** Report content is intentional user-authored input; it is sent verbatim.

## Commands

| Command | Purpose |
|---|---|
| `composer test` | Run the Pest suite |
| `composer phpstan` | Self-analysis (level max) on `src/` |
| `composer format` | Pint write |
| `composer format:check` | Pint check |

## Versioning

SemVer. Pre-1.0 (`0.x`): minor bumps are treated as breaking. `main` is always release-ready; PRs update `CHANGELOG.md` under `[Unreleased]`; a release PR moves it to a versioned heading and tags the merge commit (`v0.x.y`).

## Out of scope (v1)

A frontend feedback widget (the consuming app's own frontend posts to its backend, which relays via this package), async/queue mode, PII scrubbing, a framework-agnostic core (Laravel-only), a JS/TS client.
