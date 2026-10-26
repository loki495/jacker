<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmploymentType;
use App\Enums\LinkCheckStatus;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use App\Models\AiRequest;
use App\Models\Opportunity;

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
        $fields = $this->fields($output);
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
        $this->applier->applyFields($request->linkCheck, $fields, self::REPLACEABLE);
        $replaced = array_intersect_key($previous, array_flip($request->linkCheck->filled ?? []));
        $request->update(['status' => LinkCheckStatus::Done, 'message' => $request->linkCheck->message, 'replaced' => $replaced ?: null, 'finished_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function fields(string $output): array
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
