<?php

declare(strict_types = 1);

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoReportTool\Exceptions\ReportConfigurationException;
use ScriptDevelopment\KendoReportTool\Exceptions\ReportSubmissionException;
use ScriptDevelopment\KendoReportTool\KendoReports;

beforeEach(function(): void {
    config()->set('report-tool.kendo_url', 'https://kendo.test');
    config()->set('report-tool.project', '7');
    config()->set('report-tool.token', 'secret-token');
    config()->set('report-tool.connect_timeout', 2);
    config()->set('report-tool.timeout', 5);
    config()->set('report-tool.swallow', false);
});

/**
 * A 201 body shaped like ReportResourceData (id surfaced to the caller).
 */
function created(): array
{
    return ['id' => 42, 'title' => 'Broken', 'description' => 'It broke', 'author_name' => 'Ada'];
}

it('posts a multipart report to the project reports endpoint with the Bearer token', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    app(KendoReports::class)->submit('Broken', 'It broke', 'Ada');

    Http::assertSent(function(Request $request): bool {
        expect($request->url())->toBe('https://kendo.test/api/projects/7/reports');
        expect($request->hasHeader('Authorization', 'Bearer secret-token'))->toBeTrue();
        expect($request->isMultipart())->toBeTrue();

        return true;
    });
});

it('sends title, description and author_name as multipart fields', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    app(KendoReports::class)->submit('Broken', 'It broke', 'Ada');

    Http::assertSent(function(Request $request): bool {
        $fields = collect($request->data())->keyBy('name');

        expect($fields['title']['contents'])->toBe('Broken')
            ->and($fields['description']['contents'])->toBe('It broke')
            ->and($fields['author_name']['contents'])->toBe('Ada');

        return true;
    });
});

it('omits author_name when none is given', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    app(KendoReports::class)->submit('Broken', 'It broke');

    Http::assertSent(function(Request $request): bool {
        $names = collect($request->data())->pluck('name');

        expect($names)->not->toContain('author_name');

        return true;
    });
});

it('attaches each file as a files[] multipart part', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    app(KendoReports::class)->submit('Broken', 'It broke', 'Ada', [
        UploadedFile::fake()->image('one.png'),
        UploadedFile::fake()->image('two.jpg'),
    ]);

    Http::assertSent(function(Request $request): bool {
        $fileParts = collect($request->data())->where('name', 'files[]')->values();

        expect($fileParts)->toHaveCount(2)
            ->and($fileParts[0]['filename'])->toBe('one.png')
            ->and($fileParts[1]['filename'])->toBe('two.jpg');

        return true;
    });
});

it('returns the decoded report body on a 201', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    $report = app(KendoReports::class)->submit('Broken', 'It broke', 'Ada');

    expect($report)->toBe(created())
        ->and($report['id'])->toBe(42);
});

it('throws a ReportSubmissionException on a non-201 when not swallowing', function(): void {
    Http::fake(['kendo.test/*' => Http::response(['message' => 'nope'], 422)]);

    expect(fn() => app(KendoReports::class)->submit('Broken', 'It broke'))
        ->toThrow(ReportSubmissionException::class);
});

it('logs and returns null on a non-201 when swallowing', function(): void {
    config()->set('report-tool.swallow', true);
    Http::fake(['kendo.test/*' => Http::response(['message' => 'nope'], 422)]);

    $report = app(KendoReports::class)->submit('Broken', 'It broke');

    expect($report)->toBeNull();
});

it('surfaces a transport-level failure as a ReportSubmissionException', function(): void {
    Http::fake(function(): void {
        throw new ConnectionException('Connection timed out');
    });

    expect(fn() => app(KendoReports::class)->submit('Broken', 'It broke'))
        ->toThrow(ReportSubmissionException::class);
});

it('swallows a transport-level failure when swallowing', function(): void {
    config()->set('report-tool.swallow', true);
    Http::fake(function(): void {
        throw new ConnectionException('Connection timed out');
    });

    expect(app(KendoReports::class)->submit('Broken', 'It broke'))->toBeNull();
});

it('applies the configured connect and total timeouts to the request', function(): void {
    config()->set('report-tool.connect_timeout', 3);
    config()->set('report-tool.timeout', 9);

    $pending = Mockery::mock(PendingRequest::class);
    $pending->shouldReceive('withToken')->with('secret-token')->andReturnSelf();
    $pending->shouldReceive('connectTimeout')->with(3.0)->once()->andReturnSelf();
    $pending->shouldReceive('timeout')->with(9.0)->once()->andReturnSelf();
    $pending->shouldReceive('acceptJson')->andReturnSelf();
    $pending->shouldReceive('asMultipart')->andReturnSelf();
    $pending->shouldReceive('post')->andReturn(new Response(
        new GuzzleHttp\Psr7\Response(201, [], json_encode(created())),
    ));

    $factory = Mockery::mock(HttpFactory::class);
    $factory->shouldReceive('withToken')->with('secret-token')->andReturn($pending);

    $client = new KendoReports($factory, app(Config::class));

    expect($client->submit('Broken', 'It broke'))->toBe(created());
});

/**
 * Token-confidentiality regression pin (Sapper durability caveat).
 *
 * The report:create Bearer token must never surface in a failure message.
 * Today ReportSubmissionException carries only the HTTP status (rejected) or a
 * transport reason (failed) — never the token. These pins lock that in: a future
 * ->throw()/->retry() (which would fold the request — headers, Authorization —
 * into the thrown/logged message) would fail here before it could leak the token
 * into a caller-surfaced exception or the error_log swallow path.
 */
$secretToken = 'super-secret-report-create-token-value';

it('never leaks the report:create token in the exception surfaced on a rejection', function() use ($secretToken): void {
    config()->set('report-tool.token', $secretToken);
    Http::fake(['kendo.test/*' => Http::response(['message' => 'nope'], 422)]);

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->getMessage())
            ->toContain('422')
            ->not->toContain($secretToken);
    }
});

it('never leaks the report:create token in the exception surfaced on a transport failure', function() use ($secretToken): void {
    config()->set('report-tool.token', $secretToken);
    Http::fake(function(): void {
        // A realistic transport failure — Guzzle's ConnectionException message
        // carries the curl error, never the request's Authorization header. The
        // pin proves the client never sources the token into the failure reason
        // it surfaces (a future ->throw()/->retry() that folded the request into
        // the message would trip this).
        throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
    });

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->getMessage())
            ->toContain('Report submission failed: cURL error 28')
            ->not->toContain($secretToken);
    }
});

it('never leaks the report:create token into the error_log swallow path', function() use ($secretToken): void {
    config()->set('report-tool.token', $secretToken);
    config()->set('report-tool.swallow', true);
    Http::fake(['kendo.test/*' => Http::response(['message' => 'nope'], 422)]);

    $logFile = (string) tempnam(sys_get_temp_dir(), 'krt-errorlog-');
    $previous = ini_set('error_log', $logFile);

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        $logged = (string) file_get_contents($logFile);

        expect($logged)
            ->toContain('422')
            ->not->toContain($secretToken);
    } finally {
        if ($previous !== false) {
            ini_set('error_log', $previous);
        }
        @unlink($logFile);
    }
});

it('falls back to the default timeouts when the configured values are non-positive', function(): void {
    config()->set('report-tool.connect_timeout', 0);
    config()->set('report-tool.timeout', 'nonsense');

    $pending = Mockery::mock(PendingRequest::class);
    $pending->shouldReceive('withToken')->andReturnSelf();
    $pending->shouldReceive('connectTimeout')->with(2.0)->once()->andReturnSelf();
    $pending->shouldReceive('timeout')->with(5.0)->once()->andReturnSelf();
    $pending->shouldReceive('acceptJson')->andReturnSelf();
    $pending->shouldReceive('asMultipart')->andReturnSelf();
    $pending->shouldReceive('post')->andReturn(new Response(
        new GuzzleHttp\Psr7\Response(201, [], json_encode(created())),
    ));

    $factory = Mockery::mock(HttpFactory::class);
    $factory->shouldReceive('withToken')->andReturn($pending);

    $client = new KendoReports($factory, app(Config::class));

    expect($client->submit('Broken', 'It broke'))->toBe(created());
});

it('carries the status and the field-keyed validation errors of a 422 rejection', function(): void {
    Http::fake(['kendo.test/*' => Http::response([
        'message' => 'The title field must not be greater than 255 characters.',
        'errors' => [
            'title' => ['The title field must not be greater than 255 characters.'],
            'files.0' => ['The files.0 field must be an image.'],
        ],
    ], 422)]);

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->status())->toBe(422)
            ->and($e->errors())->toBe([
                'title' => ['The title field must not be greater than 255 characters.'],
                'files.0' => ['The files.0 field must be an image.'],
            ])
            ->and($e->getMessage())->toBe('Report submission rejected: HTTP 422 (invalid: title, files.0).');
    }
});

it('carries a token rejection as its status with no field errors', function(): void {
    Http::fake(['kendo.test/*' => Http::response(['message' => 'This token only reaches its own project.'], 403)]);

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->status())->toBe(403)
            ->and($e->errors())->toBe([]);
    }
});

it('ignores a rejection body whose errors are not a field-keyed envelope', function(): void {
    Http::fake(['kendo.test/*' => Http::response(['errors' => ['not keyed', ['nested']]], 422)]);

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->status())->toBe(422)
            ->and($e->errors())->toBe([]);
    }
});

it('carries status 0 on a transport-level failure', function(): void {
    Http::fake(function(): void {
        throw new ConnectionException('Connection timed out');
    });

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->status())->toBe(0)
            ->and($e->errors())->toBe([]);
    }
});

it('refuses more files than the server accepts before sending anything', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    $files = array_map(
        fn(int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png"),
        range(1, KendoReports::MAX_FILES + 1),
    );

    try {
        app(KendoReports::class)->submit('Broken', 'It broke', null, $files);

        throw new RuntimeException('Expected a ReportSubmissionException to be thrown.');
    } catch (ReportSubmissionException $e) {
        expect($e->status())->toBe(0)
            ->and($e->errors())->toHaveKey('files')
            ->and($e->getMessage())->toContain('6 files given, the server accepts at most 5');
    }

    Http::assertNothingSent();
});

it('sends exactly the maximum number of files', function(): void {
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    $files = array_map(
        fn(int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png"),
        range(1, KendoReports::MAX_FILES),
    );

    expect(app(KendoReports::class)->submit('Broken', 'It broke', null, $files))->toBe(created());

    Http::assertSent(fn(Request $request): bool => collect($request->data())->where('name', 'files[]')->count() === KendoReports::MAX_FILES);
});

it('swallows a refused file count when swallowing, without sending', function(): void {
    config()->set('report-tool.swallow', true);
    Http::fake(['kendo.test/*' => Http::response(created(), 201)]);

    $files = array_map(
        fn(int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png"),
        range(1, KendoReports::MAX_FILES + 1),
    );

    expect(app(KendoReports::class)->submit('Broken', 'It broke', null, $files))->toBeNull();

    Http::assertNothingSent();
});

it('refuses to send with a missing setting, even when swallowing', function(string $key, mixed $value, string $env, bool $swallow): void {
    config()->set('report-tool.' . $key, $value);
    config()->set('report-tool.swallow', $swallow);
    Http::fake(['*' => Http::response(created(), 201)]);

    expect(fn() => app(KendoReports::class)->submit('Broken', 'It broke'))
        ->toThrow(ReportConfigurationException::class, "report-tool.{$key} is empty (set {$env})");

    Http::assertNothingSent();
})->with([
    'kendo_url' => ['kendo_url', null, 'REPORT_TOOL_KENDO_URL'],
    'project' => ['project', null, 'REPORT_TOOL_PROJECT'],
    'token' => ['token', null, 'REPORT_TOOL_TOKEN'],
    'blank token' => ['token', '   ', 'REPORT_TOOL_TOKEN'],
])->with([
    'surfacing' => [false],
    'swallowing' => [true],
]);

it('refuses to send to a kendo_url that is not an absolute http(s) URL, even when swallowing', function(string $url, bool $swallow): void {
    config()->set('report-tool.kendo_url', $url);
    config()->set('report-tool.swallow', $swallow);
    Http::fake(['*' => Http::response(created(), 201)]);

    expect(fn() => app(KendoReports::class)->submit('Broken', 'It broke'))
        ->toThrow(ReportConfigurationException::class, 'report-tool.kendo_url must be an absolute http(s) URL');

    Http::assertNothingSent();
})->with([
    'no scheme' => ['kendo.test'],
    'other scheme' => ['ftp://kendo.test'],
    'no host' => ['https://'],
])->with([
    'surfacing' => [false],
    'swallowing' => [true],
]);

it('never names the configured token in a configuration failure', function(): void {
    config()->set('report-tool.token', 'super-secret-report-create-token-value');
    config()->set('report-tool.kendo_url', 'kendo.test');

    try {
        app(KendoReports::class)->submit('Broken', 'It broke');

        throw new RuntimeException('Expected a ReportConfigurationException to be thrown.');
    } catch (ReportConfigurationException $e) {
        expect($e->getMessage())->not->toContain('super-secret-report-create-token-value');
    }
});

it('reads a string swallow flag by its meaning, not by a raw cast', function(mixed $flag, bool $swallows): void {
    config()->set('report-tool.swallow', $flag);
    Http::fake(['kendo.test/*' => Http::response(['message' => 'nope'], 500)]);

    $submit = fn() => app(KendoReports::class)->submit('Broken', 'It broke');

    if ($swallows) {
        expect($submit())->toBeNull();

        return;
    }

    expect($submit)->toThrow(ReportSubmissionException::class);
})->with([
    'string "false"' => ['false', false],
    'string "0"' => ['0', false],
    'string "off"' => ['off', false],
    'unrecognised string' => ['maybe', false],
    'array' => [['true'], false],
    'string "true"' => ['true', true],
    'string "1"' => ['1', true],
    'int 1' => [1, true],
    'bool true' => [true, true],
]);
