<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmploymentType;
use App\Enums\LinkCheckStatus;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Support\ApplicationUrl;

final readonly class CompleteAiRequest
{
    /** Long text fields an explicit AI pass may replace; the earlier values are kept on the request. */
    private const array REPLACEABLE = ['benefits', 'responsibilities', 'requirements', 'hours'];

    private const array TEXT = ['title', 'company', 'company_url', 'location', 'benefits', 'responsibilities', 'requirements', 'hours', 'salary_notes'];

    public function __construct(private RunLinkCheck $applier) {}

    public function fail(AiRequest $request, string $message): void
    {
        $request->update(['status' => LinkCheckStatus::Failed, 'message' => $message, 'finished_at' => now()]);
    }

    public function handle(AiRequest $request, string $output): void
    {
        $request->output = mb_substr($output, 0, (int) config('ai.max_output_chars'));
        $fields = $this->fields($output, (string) $request->linkCheck->page_text);
        if ($fields === []) {
            $this->fail($request, __('jobs.ai_bad_output'));

            return;
        }
        $opportunity = $request->linkCheck->opportunity;
        $previous = [];
        if ($opportunity instanceof Opportunity) {
            foreach (self::REPLACEABLE as $key) {
                $old = $opportunity->getAttribute($key);
                if (isset($fields[$key]) && filled($old) && $old !== $fields[$key]) {
                    $previous[$key] = $old;
                }
            }
        }
        $replace = self::REPLACEABLE;
        $original = $opportunity instanceof Opportunity ? $opportunity->application_url : null;
        if (isset($fields['application_url'])) {
            $current = (string) parse_url((string) $original, PHP_URL_HOST);
            $taken = Opportunity::query()->where('normalized_application_url', ApplicationUrl::normalize($fields['application_url']))->whereKeyNot($opportunity->id ?? 0)->exists();
            // Only a missing or LinkedIn address is replaced; a link the owner set stays, and a duplicate would fail the whole fill.
            if ($taken || ($original !== null && $original !== '' && ! str_ends_with(strtolower($current), 'linkedin.com'))) {
                unset($fields['application_url']);
            } else {
                $replace[] = 'application_url';
            }
        }
        $this->applier->applyFields($request->linkCheck, $fields, $replace);
        if ($opportunity instanceof Opportunity && in_array('application_url', $request->linkCheck->filled ?? [], true) && filled($original)) {
            $opportunity->refresh()->update(['extra_fields' => [...($opportunity->extra_fields ?? []), 'LinkedIn posting' => $original, RequestAgentApply::UNCONFIRMED => __('jobs.apply_link_unconfirmed')]]);
        }
        $replaced = array_intersect_key($previous, array_flip($request->linkCheck->filled ?? []));
        $request->update(['status' => LinkCheckStatus::Done, 'message' => $request->linkCheck->message, 'replaced' => $replaced ?: null, 'finished_at' => now()]);
    }

    /** The address must be one whole address token written in the page text, not a fragment of one or a model invention. */
    private function isWholeAddressIn(string $url, string $text): bool
    {
        preg_match_all('#https?://[^\s<>"\'()\[\]]+#i', $text, $found);

        return in_array($url, array_map(fn (string $candidate): string => rtrim($candidate, '.,;:!?'), $found[0]), true);
    }

    /** @return array<string, mixed> */
    private function fields(string $output, string $pageText): array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        $json = $start === false || $end === false ? null : json_decode(substr($output, $start, $end - $start + 1), true);
        if (! is_array($json)) {
            return [];
        }
        $fields = [];
        foreach (self::TEXT as $key) {
            if (is_string($json[$key] ?? null) && trim($json[$key]) !== '') {
                $fields[$key] = trim($json[$key]);
            }
        }
        foreach (['work_arrangement' => WorkArrangement::class, 'employment_type' => EmploymentType::class, 'pay_period' => PayPeriod::class] as $key => $enum) {
            $value = is_string($json[$key] ?? null) ? $enum::tryFrom($json[$key]) : null;
            if ($value !== null && $value->value !== 'unspecified') {
                $fields[$key] = $value->value;
            }
        }
        $apply = $json['application_url'] ?? null;
        if (is_string($apply) && filter_var($apply, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $apply) === 1
            && ! str_ends_with(strtolower((string) parse_url($apply, PHP_URL_HOST)), 'linkedin.com') && $this->isWholeAddressIn($apply, $pageText)) {
            $fields['application_url'] = $apply;
        }
        foreach (['salary_min', 'salary_max'] as $key) {
            if (is_numeric($json[$key] ?? null) && $json[$key] >= 0) {
                $fields[$key] = (string) round((float) $json[$key], 2);
            }
        }
        if (is_string($json['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', $json['currency'])) {
            $fields['currency'] = strtoupper($json['currency']);
        }

        return $fields;
    }
}
