<?php

declare(strict_types = 1);

return [
    /*
    |--------------------------------------------------------------------------
    | Kendo URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the kendo tenant that receives reports — always
    | "https://{tenant}.kendo.dev". The client POSTs to
    | "{kendo_url}/api/projects/{project}/reports".
    |
     */

    'kendo_url' => env('REPORT_TOOL_KENDO_URL'),

    /*
    |--------------------------------------------------------------------------
    | Project
    |--------------------------------------------------------------------------
    |
    | The kendo project id that owns the reports. This is the {project}
    | route-key — kendo binds {project} by id. The project token below is
    | bound to this project and must carry the "report:create" ability.
    |
     */

    'project' => env('REPORT_TOOL_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | A kendo project token carrying the "report:create" ability. Sent as a
    | Bearer token. Mint it from the kendo project's API-token settings — a
    | report:create submission is recorded with source = Api and the human
    | author surfaced via author_name.
    |
     */

    'token' => env('REPORT_TOOL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Connect timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait while establishing the connection to the kendo host.
    |
     */

    'connect_timeout' => env('REPORT_TOOL_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Total seconds to wait for the POST to complete (connect + transfer).
    |
     */

    'timeout' => env('REPORT_TOOL_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Swallow failures
    |--------------------------------------------------------------------------
    |
    | When false (default) a failed submission surfaces to the caller so the
    | app can tell the user it did not send — a report has a human waiting on
    | confirmation. When true the client swallows failures (telemetry mode),
    | matching kendo-error-tracker semantics.
    |
     */

    'swallow' => env('REPORT_TOOL_SWALLOW', false),

];
