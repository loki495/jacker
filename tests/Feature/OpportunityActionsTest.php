<?php

declare(strict_types=1);

use App\Actions\ArchiveOpportunity;
use App\Actions\ChangeOpportunityStatus;
use App\Actions\SaveOpportunity;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\OpportunityEvent;
use App\Support\ApplicationUrl;
use App\Support\OpportunityData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('saves independent nullable scores and decimal compensation without altering precision', function (): void {
    $job = app(SaveOpportunity::class)->handle(['company' => fake()->company(), 'salary_min' => '123456.78', 'salary_max' => '234567.89', 'skill_match' => 1, 'desirability' => null, 'currency' => 'usd']);
    expect($job->salary_min)->toBe('123456.78')->and($job->salary_max)->toBe('234567.89')->and($job->skill_match)->toBe(1)->and($job->desirability)->toBeNull()->and($job->currency)->toBe('USD')->and($job->events)->toHaveCount(1);
});

it('normalizes application links without changing queries and prevents duplicates gracefully', function (): void {
    expect(ApplicationUrl::normalize(' HTTPS://Example.COM/role/?ref=A#details '))->toBe('https://example.com/role?ref=A');
    app(SaveOpportunity::class)->handle(['company' => 'First', 'application_url' => 'https://example.com/role/?ref=A#one']);
    try {
        app(SaveOpportunity::class)->handle(['company' => 'Second', 'application_url' => 'HTTPS://EXAMPLE.COM/role?ref=A#two']);
        test()->fail('A normalized duplicate must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('application_url');
    }
    expect(Opportunity::query()->count())->toBe(1);
});

it('validates data without writing any records', function (): void {
    $data = OpportunityData::validate(['company' => ' Preview ', 'status' => 'interviewing']);
    expect($data['company'])->toBe('Preview')->and($data['has_applied'])->toBeTrue()->and(Opportunity::query()->count())->toBe(0)->and(OpportunityEvent::query()->count())->toBe(0);
});

it('rejects malformed or inconsistent opportunity input', function (array $data, string $field): void {
    try {
        app(SaveOpportunity::class)->handle(array_replace(['company' => 'Example'], $data));
        test()->fail('Invalid opportunity input must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Opportunity::query()->count())->toBe(0);
})->with([
    'required company' => [['company' => ' '], 'company'],
    'unsafe application link' => [['application_url' => 'javascript:alert(1)'], 'application_url'],
    'unsafe company link' => [['company_url' => 'data:text/html,hi'], 'company_url'],
    'invalid scheme' => [['application_url' => 'ftp://example.com/job'], 'application_url'],
    'score zero' => [['skill_match' => 0], 'skill_match'],
    'score too high' => [['desirability' => 6], 'desirability'],
    'fractional score' => [['desirability' => 1.5], 'desirability'],
    'reversed salary' => [['salary_min' => '200', 'salary_max' => '100'], 'salary_max'],
    'salary precision' => [['salary_min' => '1.999'], 'salary_min'],
    'negative salary' => [['salary_min' => '-1'], 'salary_min'],
    'invalid date' => [['deadline' => '2026-02-30'], 'deadline'],
    'future application' => [['applied_at' => '2099-01-01'], 'applied_at'],
    'invalid enum' => [['status' => 'unknown'], 'status'],
    'nested extra data' => [['extra_fields' => ['bad' => ['nested']]], 'extra_fields.bad'],
]);

it('preserves unknown application dates and existing dates on status changes', function (): void {
    $job = app(SaveOpportunity::class)->handle(['company' => 'Known dates', 'applied_at' => '2020-01-02', 'deadline' => '2030-02-03', 'follow_up_at' => '2030-02-04', 'extra_fields' => ['Team' => 'Platform'], 'archived_at' => '2024-01-01T00:00:00+00:00']);
    $job = app(ChangeOpportunityStatus::class)->handle($job, OpportunityStatus::Interviewing);
    expect($job->has_applied)->toBeTrue()->and($job->applied_at->format('Y-m-d'))->toBe('2020-01-02')->and($job->deadline->format('Y-m-d'))->toBe('2030-02-03')->and($job->follow_up_at->format('Y-m-d'))->toBe('2030-02-04')->and($job->extra_fields)->toBe(['Team' => 'Platform'])->and($job->archived_at->format('Y-m-d'))->toBe('2024-01-01');
    $unknown = app(SaveOpportunity::class)->handle(['company' => 'Unknown date', 'status' => 'applied']);
    expect($unknown->has_applied)->toBeTrue()->and($unknown->applied_at)->toBeNull();
    $rejected = app(SaveOpportunity::class)->handle(['company' => 'No application', 'status' => 'rejected']);
    expect($rejected->has_applied)->toBeFalse()->and($rejected->applied_at)->toBeNull();
});

it('records status changes once and preserves history through archive and restore', function (): void {
    $job = app(SaveOpportunity::class)->handle(['company' => 'History']);
    $job = app(ChangeOpportunityStatus::class)->handle($job, OpportunityStatus::Applied);
    app(ChangeOpportunityStatus::class)->handle($job, OpportunityStatus::Applied);
    expect($job->events()->count())->toBe(2);
    $job = app(ArchiveOpportunity::class)->handle($job, true);
    expect($job->archived_at)->not->toBeNull();
    $job = app(ArchiveOpportunity::class)->handle($job, false);
    expect($job->archived_at)->toBeNull()->and($job->events()->count())->toBe(2);
});

it('preserves archive instants across configured timezones and later edits', function (string $timezone): void {
    $previousTimezone = date_default_timezone_get();
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
    try {
        $job = app(SaveOpportunity::class)->handle([
            'company' => 'Timezone regression',
            'archived_at' => '2024-01-01T00:00:00+00:00',
        ]);
        $job = app(ChangeOpportunityStatus::class)->handle($job, OpportunityStatus::Interviewing);
        expect($job->archived_at->utc()->toIso8601String())->toBe('2024-01-01T00:00:00+00:00');
    } finally {
        date_default_timezone_set($previousTimezone);
    }
})->with(['UTC', 'America/Los_Angeles', 'Asia/Tokyo']);

it('rolls back the opportunity if related history cannot be persisted', function (): void {
    OpportunityEvent::creating(function (): void {
        throw new RuntimeException('History write failed');
    });
    try {
        expect(fn () => app(SaveOpportunity::class)->handle(['company' => 'Atomic']))->toThrow(RuntimeException::class, 'History write failed');
        expect(Opportunity::query()->count())->toBe(0);
    } finally {
        OpportunityEvent::flushEventListeners();
    }
});
