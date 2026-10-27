<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OpportunityStatus;
use App\Models\InterviewQuestion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveInterviewQuestion
{
    /** @param array{stage?: string, question?: string, options?: string} $input options is one choice per line */
    public function handle(array $input, ?InterviewQuestion $question = null): InterviewQuestion
    {
        $data = Validator::make($input, [
            'stage' => ['required', Rule::in(array_map(fn (OpportunityStatus $status): string => $status->value, InterviewQuestion::stages()))],
            'question' => ['required', 'string', 'max:1000'],
            'options' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $options = array_values(array_unique(array_filter(array_map(trim(...), preg_split('/\R/', (string) ($data['options'] ?? '')) ?: []), fn (string $line): bool => $line !== '')));
        $question ??= new InterviewQuestion;
        $question->fill(['stage' => $data['stage'], 'question' => trim($data['question']), 'options' => $options ?: null]);
        if (! $question->exists) {
            $question->position = (int) InterviewQuestion::query()->where('stage', $data['stage'])->max('position') + 1;
        }
        $question->save();

        return $question;
    }
}
