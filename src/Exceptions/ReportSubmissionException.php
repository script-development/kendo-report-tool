<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool\Exceptions;

use RuntimeException;

use function array_keys;
use function implode;
use function sprintf;

/**
 * Thrown when a report submission does not return the expected 201 Created.
 *
 * A report has a human waiting on confirmation, so — unlike the fire-and-forget
 * error tracker — a failed POST surfaces to the caller by default (D2).
 *
 * status() is the HTTP status, or 0 when no response was received (a transport
 * failure, or a request refused locally before it was sent). errors() carries the
 * field-keyed validation errors of a rejected payload — fix the input and resubmit —
 * while an empty errors() on a 401/403 points at the token or project setup, which
 * no resubmission fixes.
 */
final class ReportSubmissionException extends RuntimeException
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, list<string>> $errors validation errors keyed by field
     */
    public static function rejected(int $status, array $errors = []): self
    {
        $message = $errors === []
            ? sprintf('Report submission rejected: HTTP %d.', $status)
            : sprintf('Report submission rejected: HTTP %d (invalid: %s).', $status, implode(', ', array_keys($errors)));

        $exception = new self($message, $status);
        $exception->errors = $errors;

        return $exception;
    }

    public static function failed(string $reason): self
    {
        return new self(sprintf('Report submission failed: %s', $reason));
    }

    public static function tooManyFiles(int $given, int $max): self
    {
        $exception = new self(sprintf('Report submission rejected before sending: %d files given, the server accepts at most %d.', $given, $max));
        $exception->errors = ['files' => [sprintf('At most %d files may be attached.', $max)]];

        return $exception;
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
