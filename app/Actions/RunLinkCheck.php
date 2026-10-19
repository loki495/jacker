<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\JobPageFetcher;
use App\Services\JobPostingParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class RunLinkCheck
{
    private const array BLOCKED = [401, 403, 429, 999];

    private const array SALARY = ['salary_min', 'salary_max', 'currency', 'pay_period'];

    public function __construct(
        private JobPageFetcher $fetcher,
        private JobPostingParser $parser,
        private SaveOpportunity $save,
    ) {}

    public function handle(LinkCheck $check): void
    {
        $check->update(['status' => LinkCheckStatus::Running, 'started_at' => now()]);
        $opportunity = $check->opportunity;
        try {
            $page = $this->fetcher->fetch($check->url);
        } catch (InvalidArgumentException|ConnectionException|\RuntimeException $exception) {
            $this->finish($check, $opportunity, LinkCheckStatus::Failed, LinkResult::Error, $exception->getMessage());

            return;
        }

        $result = match (true) {
            $page['status'] >= 200 && $page['status'] < 300 => LinkResult::Ok,
            in_array($page['status'], [404, 410], true) => LinkResult::Dead,
            in_array($page['status'], self::BLOCKED, true) => LinkResult::Blocked,
            default => LinkResult::Error,
        };
        $check->http_status = $page['status'];
        if ($result !== LinkResult::Ok) {
            $this->finish($check, $opportunity, $result === LinkResult::Error ? LinkCheckStatus::Failed : LinkCheckStatus::Done, $result, __('jobs.link_http', ['status' => $page['status']]));

            return;
        }

        $fields = $this->parser->parse($page['body']);
        if (isset($fields['company_url']) && ! filter_var($fields['company_url'], FILTER_VALIDATE_URL)) {
            unset($fields['company_url']);
        }
        $opportunity instanceof Opportunity ? $this->fill($check, $opportunity, $fields) : $this->create($check, $fields);
    }

    /** @param array<string, mixed> $fields */
    private function fill(LinkCheck $check, Opportunity $opportunity, array $fields): void
    {
        $fill = array_filter($fields, fn (string $key): bool => ! in_array($key, ['company', ...self::SALARY], true) && $this->isEmpty($opportunity->{$key}), ARRAY_FILTER_USE_KEY);
        if (isset($fields['salary_min']) || isset($fields['salary_max'])) {
            if ($opportunity->salary_min === null && $opportunity->salary_max === null) {
                $fill += array_intersect_key($fields, array_flip(self::SALARY));
            }
        }
        if ($fill === []) {
            $this->finish($check, $opportunity, LinkCheckStatus::Done, LinkResult::Ok, $fields === [] ? __('jobs.link_no_data') : __('jobs.link_nothing_new'));

            return;
        }
        try {
            $this->save->handle($fill, $opportunity);
        } catch (ValidationException $exception) {
            $this->finish($check, $opportunity, LinkCheckStatus::Failed, LinkResult::Ok, implode(' ', $exception->validator->errors()->all()));

            return;
        }
        $check->filled = array_keys($fill);
        $this->finish($check, $opportunity, LinkCheckStatus::Done, LinkResult::Ok, __('jobs.link_filled', ['fields' => implode(', ', array_keys($fill))]));
    }

    /** @param array<string, mixed> $fields */
    private function create(LinkCheck $check, array $fields): void
    {
        if (! isset($fields['company'])) {
            $this->finish($check, null, LinkCheckStatus::Failed, LinkResult::Ok, __('jobs.link_no_data'));

            return;
        }
        try {
            $opportunity = $this->save->handle($fields + ['application_url' => $check->url, 'source' => __('jobs.url_import_source')]);
        } catch (ValidationException $exception) {
            $this->finish($check, null, LinkCheckStatus::Failed, LinkResult::Ok, implode(' ', $exception->validator->errors()->all()));

            return;
        }
        $check->opportunity_id = $opportunity->id;
        $check->filled = array_keys($fields);
        $this->finish($check, $opportunity, LinkCheckStatus::Done, LinkResult::Ok, __('jobs.link_created'));
    }

    private function finish(LinkCheck $check, ?Opportunity $opportunity, LinkCheckStatus $status, LinkResult $result, string $message): void
    {
        $check->fill(['status' => $status, 'result' => $result, 'message' => $message, 'finished_at' => now()])->save();
        $opportunity?->forceFill(['link_status' => $result->value, 'link_checked_at' => now()])->saveQuietly();
    }

    private function isEmpty(mixed $value): bool
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;

        return in_array($value, [null, '', 'unspecified'], true);
    }
}
