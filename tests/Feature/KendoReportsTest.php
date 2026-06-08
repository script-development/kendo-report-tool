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
