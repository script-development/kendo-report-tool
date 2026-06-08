<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use ScriptDevelopment\KendoReportTool\Exceptions\ReportSubmissionException;
use Throwable;

use function array_filter;
use function basename;
use function error_log;
use function file_get_contents;
use function is_numeric;
use function is_scalar;
use function mb_rtrim;
use function sprintf;

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
     * @param list<UploadedFile|string> $files uploadedFile instances or local file paths
     *
     * @return array<string, mixed>|null the decoded 201 body, or null when swallowing a failure
     */
    public function submit(string $title, string $description, ?string $authorName = null, array $files = []): ?array
    {
        try {
            $response = $this->pendingRequest($files)->post($this->endpoint(), $this->fields($title, $description, $authorName));
        } catch (Throwable $e) {
            return $this->fail(ReportSubmissionException::failed($e->getMessage()));
        }

        if ($response->status() !== 201) {
            return $this->fail(ReportSubmissionException::rejected($response->status()));
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
        return sprintf(
            '%s/api/projects/%s/reports',
            mb_rtrim($this->configString('kendo_url'), '/'),
            $this->configString('project'),
        );
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
    private function pendingRequest(array $files): PendingRequest
    {
        $request = $this->http
            ->withToken($this->configString('token'))
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

    private function configBool(string $key): bool
    {
        return (bool) $this->config->get('report-tool.' . $key);
    }
}
