<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Support\Desirability;
use App\Support\ScoringSettings;

final readonly class CompleteScoreRequest
{
    public function __construct(private SaveOpportunity $save) {}

    public function fail(AiRequest $request, string $message): void
    {
        $request->update(['status' => LinkCheckStatus::Failed, 'message' => $message, 'finished_at' => now()]);
    }

    public function handle(AiRequest $request, string $output): void
    {
        $request->output = mb_substr($output, 0, (int) config('ai.max_output_chars'));
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        $json = $start === false || $end === false ? null : json_decode(substr($output, $start, $end - $start + 1), true);
        $match = is_array($json) && is_numeric($json['skill_match'] ?? null) ? (int) round((float) $json['skill_match']) : 0;
        $job = $request->opportunity;
        if (! $job instanceof Opportunity || $match < 1 || $match > 5) {
            $this->fail($request, __('jobs.score_bad_output'));

            return;
        }
        $eastern = ($json['eastern_only_hours'] ?? false) === true;
        $desirability = Desirability::score($job, $eastern);
        $note = is_string($json['note'] ?? null) ? mb_substr(trim($json['note']), 0, 300) : '';
        $previous = ['skill_match' => $job->skill_match, 'desirability' => $job->desirability];
        $this->save->handle([
            'skill_match' => $match,
            'desirability' => $desirability,
            'skill_match_notes' => $note !== '' ? 'AI: '.$note : $job->skill_match_notes,
            'desirability_notes' => sprintf('AI: %d%% pay midpoint, %d%% work mode', round((float) ScoringSettings::get('pay_weight') * 100), round((1 - (float) ScoringSettings::get('pay_weight')) * 100)).($eastern ? '; Eastern-only hours -'.ScoringSettings::get('eastern_penalty') : '').'.',
        ], $job);
        $request->update([
            'status' => LinkCheckStatus::Done, 'message' => __('jobs.score_done', ['match' => $match, 'desirability' => $desirability]),
            'replaced' => array_filter($previous, fn ($value): bool => $value !== null) ?: null, 'finished_at' => now(),
        ]);
    }
}
