<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoReportTool\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown when a report submission does not return the expected 201 Created.
 *
 * A report has a human waiting on confirmation, so — unlike the fire-and-forget
 * error tracker — a failed POST surfaces to the caller by default (D2). The HTTP
 * status (or 0 for a transport-level failure such as a timeout) is carried so the
 * app can distinguish a rejection (4xx/5xx) from an unreachable host.
 */
final class ReportSubmissionException extends RuntimeException
{
    public static function rejected(int $status): self
    {
        return new self(sprintf('Report submission rejected: HTTP %d.', $status));
    }

    public static function failed(string $reason): self
    {
        return new self(sprintf('Report submission failed: %s', $reason));
    }
}
