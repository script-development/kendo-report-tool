<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use ScriptDevelopment\KendoReportTool\Exceptions\ReportConfigurationException;
use ScriptDevelopment\KendoReportTool\Exceptions\ReportSubmissionException;
use Throwable;

use function array_filter;
use function array_values;
use function basename;
use function count;
use function error_log;
use function file_get_contents;
use function filesize;
use function filter_var;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_numeric;
use function is_readable;
use function is_scalar;
use function is_string;
use function mb_rtrim;
use function mb_trim;
use function parse_url;
use function sprintf;
use function strpbrk;

/**
 * The public client surface: submit a human-authored report into a kendo project.
 *
 * Unlike kendo-error-tracker (fire-and-forget telemetry), a report has a human
 * waiting on confirmation, so the send is synchronous — no queue (D3) — and a
 * failure is surfaced to the caller by default — throws (D2). It swallows
 * (logs + returns null) only when report-tool.swallow is true. The payload is
 * sent verbatim: no PII scrubbing (D4). Files are attached as multipart parts,
 * not JSON (D3) — calling asJson() would break the file upload.
 */
final readonly class KendoReports
{
    /**
     * The server's own cap (`files` => max:5). Enforced here so an oversized call
     * fails with a named reason before the upload, not as a bare HTTP 422.
     */
    public const int MAX_FILES = 5;

    /**
     * The server's per-file cap (`files.*` => max('3mb')): Laravel's File rule reads
     * "3mb" as 3000 kilobytes of 1024 bytes. Checked before a file is read, so an
     * oversized attachment never enters this process's memory.
     */
    public const int MAX_FILE_BYTES = 3_072_000;

    public function __construct(
        private HttpFactory $http,
        private Config $config,
    ) {}

    /**
     * Submit a report. On a 201 the decoded report body is returned (id + fields).
     * On any other outcome — a non-201 status or a transport-level failure (timeout,
     * unreachable host) — the caller gets a ReportSubmissionException, unless
     * report-tool.swallow is true, in which case the failure is logged to the local
     * PHP error_log and null is returned.
     *
     * A missing or malformed kendo_url / project / token throws a
     * ReportConfigurationException before anything is sent, whatever swallow says.
     *
     * More than MAX_FILES files is refused before anything is sent, as the same
     * ReportSubmissionException (errors() keyed by `files`). So is a file larger than
     * MAX_FILE_BYTES, or a path that is not a readable file (errors() keyed by
     * `files.{index}`), before any file is read.
     *
     * @param list<UploadedFile|string> $files uploadedFile instances or local file paths
     *
     * @return array<string, mixed>|null the decoded 201 body, or null when swallowing a failure
     */
    public function submit(string $title, string $description, ?string $authorName = null, array $files = []): ?array
    {
        $endpoint = $this->endpoint();
        $token = $this->requiredString('token', 'REPORT_TOOL_TOKEN');

        if (count($files) > self::MAX_FILES) {
            return $this->fail(ReportSubmissionException::tooManyFiles(count($files), self::MAX_FILES));
        }

        foreach ($files as $index => $file) {
            $refusal = $this->refusal($index, $file);

            if ($refusal !== null) {
                return $this->fail($refusal);
            }
        }

        try {
            $response = $this->pendingRequest($token, $files)->post($endpoint, $this->fields($title, $description, $authorName));
        } catch (Throwable $e) {
            return $this->fail(ReportSubmissionException::failed($e->getMessage()));
        }

        if ($response->status() !== 201) {
            return $this->fail(ReportSubmissionException::rejected($response->status(), $this->validationErrors($response)));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * Build the URL: "{kendo_url}/api/projects/{project}/reports". {project} is the
     * kendo project id (route-bound by id).
     */
    private function endpoint(): string
    {
        $url = mb_rtrim($this->requiredString('kendo_url', 'REPORT_TOOL_KENDO_URL'), '/');

        // The route is appended to the URL text, so a query would swallow it and a fragment never reaches the server.
        if (
            !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || (string) parse_url($url, PHP_URL_HOST) === ''
            || strpbrk($url, '?#') !== false
        ) {
            throw ReportConfigurationException::invalidUrl();
        }

        return sprintf('%s/api/projects/%s/reports', $url, $this->requiredString('project', 'REPORT_TOOL_PROJECT'));
    }

    /**
     * The scalar multipart fields. author_name is the snake_case wire key
     * (StoreReportRequest rules / CreateReportData); a null author is omitted
     * rather than sent as an empty part.
     *
     * @return array<string, string>
     */
    private function fields(string $title, string $description, ?string $authorName): array
    {
        return array_filter([
            'title' => $title,
            'description' => $description,
            'author_name' => $authorName,
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * Build the pending request: Bearer auth, bounded connect/total timeouts, and
     * one multipart attachment per file. attach() puts the request into multipart
     * mode so the scalar fields ride alongside as form parts — never call asJson(),
     * which would break the file upload.
     *
     * @param list<UploadedFile|string> $files
     */
    private function pendingRequest(string $token, array $files): PendingRequest
    {
        $request = $this->http
            ->withToken($token)
            ->connectTimeout($this->configFloat('connect_timeout', 2.0))
            ->timeout($this->configFloat('timeout', 5.0))
            ->acceptJson()
            ->asMultipart();

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $request = $request->attach('files[]', (string) $file->getContent(), $file->getClientOriginalName());

                continue;
            }

            $request = $request->attach('files[]', (string) file_get_contents($file), basename($file));
        }

        return $request;
    }

    /**
     * Why a file cannot be sent, judged from its size alone — the file is never
     * opened here. is_file() first, because filesize() warns on a missing path and
     * a host app turns that warning into an exception that would bypass swallow.
     */
    private function refusal(int $index, string|UploadedFile $file): ?ReportSubmissionException
    {
        if ($file instanceof UploadedFile) {
            $name = $file->getClientOriginalName();
            $size = $file->getSize();
        } else {
            $name = basename($file);
            $size = is_file($file) && is_readable($file) ? filesize($file) : false;
        }

        if ($size === false) {
            return ReportSubmissionException::unreadableFile($index, $name);
        }

        if ($size > self::MAX_FILE_BYTES) {
            return ReportSubmissionException::fileTooLarge($index, $name, $size, self::MAX_FILE_BYTES);
        }

        return null;
    }

    /**
     * The `errors` object of a rejection body, narrowed to field => messages.
     * Anything not shaped like Laravel's validation envelope yields [].
     *
     * @return array<string, list<string>>
     */
    private function validationErrors(Response $response): array
    {
        $errors = $response->json('errors');

        if (!is_array($errors)) {
            return [];
        }

        $narrowed = [];

        foreach ($errors as $field => $messages) {
            if (!is_string($field) || !is_array($messages)) {
                continue;
            }

            $narrowed[$field] = array_values(array_filter($messages, is_string(...)));
        }

        return $narrowed;
    }

    /**
     * Surface a failure: throw by default (D2), or log + return null when
     * report-tool.swallow is true.
     */
    private function fail(ReportSubmissionException $exception): null
    {
        if ($this->configBool('swallow')) {
            error_log(sprintf('[kendo-report-tool] %s', $exception->getMessage()));

            return null;
        }

        throw $exception;
    }

    /**
     * Read a string config value, narrowing the repository's mixed return.
     * Non-scalar / null values collapse to an empty string.
     */
    private function configString(string $key): string
    {
        $value = $this->config->get('report-tool.' . $key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read a float config value, narrowing the repository's mixed return.
     *
     * A non-positive numeric (e.g. 0) means "wait forever" to Guzzle, which would
     * let a hung kendo host block the caller — so it falls back to the default,
     * mirroring kendo-error-tracker's timeout floor.
     */
    private function configFloat(string $key, float $default): float
    {
        $value = $this->config->get('report-tool.' . $key);

        if (!is_numeric($value)) {
            return $default;
        }

        $coerced = (float) $value;

        return $coerced > 0.0 ? $coerced : $default;
    }

    /**
     * Read a string config value that the send cannot do without.
     */
    private function requiredString(string $key, string $env): string
    {
        $value = mb_trim($this->configString($key));

        if ($value === '') {
            throw ReportConfigurationException::missing($key, $env);
        }

        return $value;
    }

    /**
     * Read a boolean config value, narrowing the repository's mixed return. A
     * string is parsed ("false", "0", "off" are false — a raw cast would make the
     * string "false" true); anything unrecognised is false.
     */
    private function configBool(string $key): bool
    {
        $value = $this->config->get('report-tool.' . $key);

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
